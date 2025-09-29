<?php

namespace App\Http\Controllers\Figma;

/**
 * @OA\Info(
 *     version="1.0.0",
 *     title="Figma APIs",
 *     description="API for converting Figma variables to Tailwind config"
 * )
 */

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Response;

class TailwindConfigController extends Controller
{
    /**
     * @OA\Post(
     *     path="/api/figma/tailwind-config",
     *     summary="Generate Tailwind config from Figma variables",
     *     tags={"Figma"},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"fileKey"},
     *
     *             @OA\Property(property="fileKey", type="string", example="6uZ8mS1F4Oi7aL0VJLau49")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Tailwind config JS string",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="config", type="string", example="module.exports = { theme: { colors: { ... } } };")
     *         )
     *     ),
     *
     *     @OA\Response(response=400, description="Bad request")
     * )
     */
    public function generate(Request $request)
    {
        $request->validate([
            'fileKey' => 'required|string',
        ]);

        $fileKey = $request->input('fileKey');
        $figmaToken = env('FIGMA_TOKEN');

        if (empty($figmaToken)) {
            Log::error('Figma token not configured');

            return Response::json(['error' => 'Figma token not configured'], 500);
        }

        $url = "https://api.figma.com/v1/files/{$fileKey}/variables/local";

        try {
            $response = Http::withHeaders([
                'x-figma-token' => $figmaToken,
            ])->timeout(60 * 5)->get($url);

            if (! $response->successful()) {
                Log::error('Figma API error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'url' => $url,
                ]);

                return Response::json([
                    'error' => 'Failed to fetch Figma variables',
                    'details' => $response->status() === 404 ? 'File not found or no access' : 'API request failed',
                ], $response->status());
            }

            $variables = $response->json();

            if (empty($variables)) {
                return Response::json(['error' => 'No variables found in Figma file'], 400);
            }

            // Debug: Log the structure for troubleshooting
            Log::info('Figma variables structure', ['keys' => array_keys($variables)]);

            $theme = $this->mapFigmaVariablesToTailwindTheme($variables);

            if (empty($theme)) {
                return Response::json(['error' => 'No valid variables could be processed'], 400);
            }

            $configJs = $this->generateTailwindConfig($theme);

            return Response::json([
                'config' => $configJs,
                'variableCount' => $this->countProcessedVariables($variables),
                'themeKeys' => array_keys($theme),
            ]);

        } catch (\Exception $e) {
            Log::error('Error processing Figma variables', [
                'error' => $e->getMessage(),
                'fileKey' => $fileKey,
            ]);

            return Response::json(['error' => 'Internal server error'], 500);
        }
    }

    private function mapFigmaVariablesToTailwindTheme($variables)
    {
        // Initialize complete theme structure
        $theme = [
            'colors' => [],
            'spacing' => [],
            'fontSize' => [],
            'fontFamily' => [], // This was missing!
            'fontWeight' => [],
            'lineHeight' => [],
            'letterSpacing' => [],
            'borderRadius' => [],
            'borderWidth' => [],
            'borderColor' => [],
            'borderStyle' => [],
            'backgroundColor' => [],
            'backgroundImage' => [],
            'backgroundSize' => [],
            'backgroundPosition' => [],
            'width' => [],
            'height' => [],
            'minWidth' => [],
            'minHeight' => [],
            'maxWidth' => [],
            'maxHeight' => [],
            'margin' => [],
            'padding' => [],
            'inset' => [],
            'zIndex' => [],
            'opacity' => [],
            'boxShadow' => [],
            'dropShadow' => [],
            'blur' => [],
            'brightness' => [],
            'contrast' => [],
            'grayscale' => [],
            'hueRotate' => [],
            'invert' => [],
            'saturate' => [],
            'sepia' => [],
            'transitionDuration' => [],
            'transitionTimingFunction' => [],
            'transitionDelay' => [],
            'transitionProperty' => [],
            'animation' => [],
            'keyframes' => [],
            'cursor' => [],
            'userSelect' => [],
            'pointerEvents' => [],
            'resize' => [],
            'scrollBehavior' => [],
            'listStyleType' => [],
            'listStylePosition' => [],
            'appearance' => [],
            'gridTemplateColumns' => [],
            'gridColumn' => [],
            'gridTemplateRows' => [],
            'gridRow' => [],
            'gridAutoColumns' => [],
            'gridAutoRows' => [],
            'gap' => [],
            'columnGap' => [],
            'rowGap' => [],
            'justifyContent' => [],
            'justifyItems' => [],
            'justifySelf' => [],
            'alignContent' => [],
            'alignItems' => [],
            'alignSelf' => [],
            'placeContent' => [],
            'placeItems' => [],
            'placeSelf' => [],
            'flex' => [],
            'flexGrow' => [],
            'flexShrink' => [],
            'flexBasis' => [],
            'flexDirection' => [],
            'flexWrap' => [],
            'order' => [],
            'aspectRatio' => [],
            'container' => [],
            'columns' => [],
            'breakAfter' => [],
            'breakBefore' => [],
            'breakInside' => [],
            'boxDecorationBreak' => [],
            'boxSizing' => [],
            'display' => [],
            'float' => [],
            'clear' => [],
            'isolation' => [],
            'objectFit' => [],
            'objectPosition' => [],
            'overflow' => [],
            'overflowX' => [],
            'overflowY' => [],
            'overscrollBehavior' => [],
            'overscrollBehaviorX' => [],
            'overscrollBehaviorY' => [],
            'position' => [],
            'visibility' => [],
            'textAlign' => [],
            'textColor' => [],
            'textDecoration' => [],
            'textDecorationColor' => [],
            'textDecorationStyle' => [],
            'textDecorationThickness' => [],
            'textUnderlineOffset' => [],
            'textTransform' => [],
            'textOverflow' => [],
            'textIndent' => [],
            'verticalAlign' => [],
            'whitespace' => [],
            'wordBreak' => [],
            'content' => [],
            'outlineWidth' => [],
            'outlineColor' => [],
            'outlineStyle' => [],
            'outlineOffset' => [],
            'ringWidth' => [],
            'ringColor' => [],
            'ringOpacity' => [],
            'ringOffsetWidth' => [],
            'ringOffsetColor' => [],
            'fill' => [],
            'stroke' => [],
            'strokeWidth' => [],
        ];

        // Handle different Figma API response structures
        $variablesToProcess = [];

        if (isset($variables['variables'])) {
            $variablesToProcess = $variables['variables'];
        } elseif (isset($variables['meta']['variables'])) {
            $variablesToProcess = $variables['meta']['variables'];
        } elseif (is_array($variables)) {
            // Sometimes the response is directly an array of variables
            $variablesToProcess = $variables;
        }

        // Process variable collections if they exist
        if (isset($variables['meta']['variableCollections'])) {
            $collections = $variables['meta']['variableCollections'];
            Log::info('Found variable collections', ['count' => count($collections)]);
        }

        foreach ($variablesToProcess as $varId => $var) {
            if (! $this->isValidVariable($var)) {
                continue;
            }

            $this->processVariable($var, $theme, $varId);
        }

        // Remove empty arrays for cleaner output
        $theme = array_filter($theme, function ($val) {
            return ! empty($val);
        });

        return $theme;
    }

    private function isValidVariable($var)
    {
        return is_array($var) &&
               (isset($var['name']) || isset($var['key'])) &&
               (isset($var['resolvedType']) || isset($var['type'])) &&
               (isset($var['resolvedValue']) || isset($var['value']) || isset($var['valuesByMode']));
    }

    private function processVariable($var, &$theme, $varId)
    {
        // Handle different variable structure formats
        $name = $var['name'] ?? $var['key'] ?? $varId;
        $type = $var['resolvedType'] ?? $var['type'] ?? null;
        $value = $var['resolvedValue'] ?? $var['value'] ?? null;

        // Handle variables with modes
        if (! $value && isset($var['valuesByMode']) && is_array($var['valuesByMode'])) {
            // Take the first mode's value
            $value = reset($var['valuesByMode']);
        }

        if (! $type || $value === null) {
            return;
        }

        // Clean up the name for use as a CSS variable
        $cleanName = $this->cleanVariableName($name);

        switch (strtoupper($type)) {
            case 'COLOR':
                $this->processColorVariable($cleanName, $value, $theme);
                break;

            case 'FLOAT':
            case 'NUMBER':
                $this->processNumericVariable($cleanName, $value, $theme);
                break;

            case 'STRING':
                $this->processStringVariable($cleanName, $value, $theme);
                break;

            case 'BOOLEAN':
                $theme['boolean'][$cleanName] = $value;
                break;

            default:
                Log::info('Unknown variable type', ['type' => $type, 'name' => $name]);
                $theme['other'][$cleanName] = $value;
                break;
        }
    }

    private function cleanVariableName($name)
    {
        // Convert Figma variable names to valid CSS/Tailwind names
        // Replace forward slashes and other invalid characters with hyphens
        $name = str_replace('/', '-', $name);
        $name = preg_replace('/[^a-zA-Z0-9\-_]/', '-', $name);
        $name = preg_replace('/-+/', '-', $name);
        $name = trim($name, '-');

        return strtolower($name);
    }

    private function processColorVariable($name, $value, &$theme)
    {
        $processedColor = $this->processColorValue($value);

        if ($processedColor) {
            $theme['colors'][$name] = $processedColor;

            // Also add to related color properties based on semantic naming
            if (stripos($name, 'background') !== false || stripos($name, 'bg') !== false) {
                $theme['backgroundColor'][$name] = $processedColor;
            } elseif (stripos($name, 'border') !== false) {
                $theme['borderColor'][$name] = $processedColor;
            } elseif (stripos($name, 'text') !== false) {
                $theme['textColor'][$name] = $processedColor;
            } elseif (stripos($name, 'icon') !== false) {
                $theme['textColor'][$name] = $processedColor; // Icons often use text color
            } elseif (stripos($name, 'ring') !== false) {
                $theme['ringColor'][$name] = $processedColor;
            } elseif (stripos($name, 'outline') !== false) {
                $theme['outlineColor'][$name] = $processedColor;
            } else {
                // For generic colors, add to common color properties
                $theme['backgroundColor'][$name] = $processedColor;
                $theme['borderColor'][$name] = $processedColor;
                $theme['textColor'][$name] = $processedColor;
            }
        }
    }

    private function processColorValue($value)
    {
        if (is_string($value)) {
            // Handle hex colors
            if (preg_match('/^#([A-Fa-f0-9]{3,8})$/', $value)) {
                return $value;
            }
            // Handle other string color formats
            if (preg_match('/^(rgb|rgba|hsl|hsla)\(/', $value)) {
                return $value;
            }
        } elseif (is_array($value)) {
            // Handle Figma RGBA object format
            if (isset($value['r'], $value['g'], $value['b'])) {
                $r = round($value['r'] * 255);
                $g = round($value['g'] * 255);
                $b = round($value['b'] * 255);
                $a = $value['a'] ?? 1;

                if ($a < 1) {
                    return "rgba({$r}, {$g}, {$b}, {$a})";
                } else {
                    return sprintf('#%02x%02x%02x', $r, $g, $b);
                }
            }
        }

        return null;
    }

    private function processNumericVariable($name, $value, &$theme)
    {
        // Ensure numeric value
        if (! is_numeric($value)) {
            return;
        }

        $processedValue = $this->processNumericValue($value, $name);

        // Map based on variable name patterns (order matters - more specific first)
        if (stripos($name, 'letter-spacing') !== false || stripos($name, 'letterspacing') !== false) {
            $theme['letterSpacing'][$name] = $processedValue;
        } elseif (stripos($name, 'line-height') !== false || stripos($name, 'lineheight') !== false) {
            $theme['lineHeight'][$name] = $processedValue;
        } elseif (stripos($name, 'font-weight') !== false || stripos($name, 'fontweight') !== false) {
            $theme['fontWeight'][$name] = (int) $value; // Font weights should be integers
        } elseif (stripos($name, 'font-size') !== false || stripos($name, 'fontsize') !== false) {
            $theme['fontSize'][$name] = $processedValue;
        } elseif (stripos($name, 'border-radius') !== false || stripos($name, 'radius') !== false) {
            $theme['borderRadius'][$name] = $processedValue;
        } elseif (stripos($name, 'border-width') !== false) {
            $theme['borderWidth'][$name] = $processedValue;
        } elseif (stripos($name, 'outline-width') !== false) {
            $theme['outlineWidth'][$name] = $processedValue;
        } elseif (stripos($name, 'outline-offset') !== false) {
            $theme['outlineOffset'][$name] = $processedValue;
        } elseif (stripos($name, 'ring-width') !== false) {
            $theme['ringWidth'][$name] = $processedValue;
        } elseif (stripos($name, 'ring-offset-width') !== false) {
            $theme['ringOffsetWidth'][$name] = $processedValue;
        } elseif (stripos($name, 'stroke-width') !== false) {
            $theme['strokeWidth'][$name] = $processedValue;
        } elseif (stripos($name, 'min-width') !== false || stripos($name, 'minwidth') !== false) {
            $theme['minWidth'][$name] = $processedValue;
        } elseif (stripos($name, 'max-width') !== false || stripos($name, 'maxwidth') !== false) {
            $theme['maxWidth'][$name] = $processedValue;
        } elseif (stripos($name, 'min-height') !== false || stripos($name, 'minheight') !== false) {
            $theme['minHeight'][$name] = $processedValue;
        } elseif (stripos($name, 'max-height') !== false || stripos($name, 'maxheight') !== false) {
            $theme['maxHeight'][$name] = $processedValue;
        } elseif (stripos($name, 'width') !== false) {
            $theme['width'][$name] = $processedValue;
        } elseif (stripos($name, 'height') !== false) {
            $theme['height'][$name] = $processedValue;
        } elseif (stripos($name, 'margin') !== false) {
            $theme['margin'][$name] = $processedValue;
            $theme['spacing'][$name] = $processedValue; // Also add to spacing
        } elseif (stripos($name, 'padding') !== false) {
            $theme['padding'][$name] = $processedValue;
            $theme['spacing'][$name] = $processedValue; // Also add to spacing
        } elseif (stripos($name, 'space') !== false || stripos($name, 'spacing') !== false) {
            $theme['spacing'][$name] = $processedValue;
        } elseif (stripos($name, 'gap') !== false) {
            $theme['gap'][$name] = $processedValue;
        } elseif (stripos($name, 'column-gap') !== false) {
            $theme['columnGap'][$name] = $processedValue;
        } elseif (stripos($name, 'row-gap') !== false) {
            $theme['rowGap'][$name] = $processedValue;
        } elseif (stripos($name, 'z-index') !== false || stripos($name, 'zindex') !== false) {
            $theme['zIndex'][$name] = (int) $value; // Z-index should be integer
        } elseif (stripos($name, 'opacity') !== false) {
            $theme['opacity'][$name] = $value; // Keep opacity as decimal
        } elseif (stripos($name, 'flex-grow') !== false) {
            $theme['flexGrow'][$name] = $value;
        } elseif (stripos($name, 'flex-shrink') !== false) {
            $theme['flexShrink'][$name] = $value;
        } elseif (stripos($name, 'flex-basis') !== false) {
            $theme['flexBasis'][$name] = $processedValue;
        } elseif (stripos($name, 'flex') !== false) {
            $theme['flex'][$name] = $value;
        } elseif (stripos($name, 'order') !== false && is_numeric($value) && $value >= 0) {
            $theme['order'][$name] = (int) $value;
        } elseif (stripos($name, 'aspect-ratio') !== false) {
            $theme['aspectRatio'][$name] = $value;
        } elseif (stripos($name, 'transition-duration') !== false) {
            $theme['transitionDuration'][$name] = $this->ensureTimeUnit($processedValue);
        } elseif (stripos($name, 'transition-delay') !== false) {
            $theme['transitionDelay'][$name] = $this->ensureTimeUnit($processedValue);
        } elseif (stripos($name, 'animation-duration') !== false) {
            $theme['animation'][$name] = $this->ensureTimeUnit($processedValue);
        } elseif (stripos($name, 'blur') !== false) {
            $theme['blur'][$name] = $processedValue;
        } elseif (stripos($name, 'brightness') !== false) {
            $theme['brightness'][$name] = $value;
        } elseif (stripos($name, 'contrast') !== false) {
            $theme['contrast'][$name] = $value;
        } elseif (stripos($name, 'grayscale') !== false) {
            $theme['grayscale'][$name] = $value;
        } elseif (stripos($name, 'hue-rotate') !== false) {
            $theme['hueRotate'][$name] = $this->ensureDegreeUnit($value);
        } elseif (stripos($name, 'invert') !== false) {
            $theme['invert'][$name] = $value;
        } elseif (stripos($name, 'saturate') !== false) {
            $theme['saturate'][$name] = $value;
        } elseif (stripos($name, 'sepia') !== false) {
            $theme['sepia'][$name] = $value;
        } elseif (stripos($name, 'inset') !== false || stripos($name, 'top') !== false || stripos($name, 'right') !== false || stripos($name, 'bottom') !== false || stripos($name, 'left') !== false) {
            $theme['inset'][$name] = $processedValue;
        } else {
            // Default to spacing if no specific pattern matches
            $theme['spacing'][$name] = $processedValue;
        }
    }

    private function processNumericValue($value, $name)
    {
        // Add appropriate units based on context
        if (stripos($name, 'opacity') !== false || stripos($name, 'flex') !== false) {
            return $value; // Unitless values
        }

        if (stripos($name, 'font-weight') !== false || stripos($name, 'z-index') !== false || stripos($name, 'order') !== false) {
            return (int) $value; // Integer values
        }

        if (stripos($name, 'line-height') !== false) {
            return $value; // Line height can be unitless
        }

        // Add px for size-related properties if no unit is present
        if (is_numeric($value) && ! preg_match('/\d+(px|rem|em|%|vh|vw|pt)$/', $value)) {
            return $value.'px';
        }

        return $value;
    }

    private function ensureTimeUnit($value)
    {
        if (is_numeric($value)) {
            return $value.'ms';
        }

        return $value;
    }

    private function ensureDegreeUnit($value)
    {
        if (is_numeric($value)) {
            return $value.'deg';
        }

        return $value;
    }

    private function processStringVariable($name, $value, &$theme)
    {
        // Process string variables
        if (stripos($name, 'font-family') !== false || stripos($name, 'fontfamily') !== false) {
            // Handle font families - split by comma and clean up
            $fonts = array_map('trim', explode(',', $value));
            $theme['fontFamily'][$name] = $fonts;
        } elseif (stripos($name, 'transition-timing-function') !== false || stripos($name, 'easing') !== false) {
            $theme['transitionTimingFunction'][$name] = $value;
        } elseif (stripos($name, 'transition-property') !== false) {
            $theme['transitionProperty'][$name] = $value;
        } elseif (stripos($name, 'cursor') !== false) {
            $theme['cursor'][$name] = $value;
        } elseif (stripos($name, 'background-image') !== false || stripos($name, 'gradient') !== false) {
            $theme['backgroundImage'][$name] = $value;
        } elseif (stripos($name, 'background-size') !== false) {
            $theme['backgroundSize'][$name] = $value;
        } elseif (stripos($name, 'background-position') !== false) {
            $theme['backgroundPosition'][$name] = $value;
        } elseif (stripos($name, 'border-style') !== false) {
            $theme['borderStyle'][$name] = $value;
        } elseif (stripos($name, 'outline-style') !== false) {
            $theme['outlineStyle'][$name] = $value;
        } elseif (stripos($name, 'text-align') !== false) {
            $theme['textAlign'][$name] = $value;
        } elseif (stripos($name, 'text-transform') !== false) {
            $theme['textTransform'][$name] = $value;
        } elseif (stripos($name, 'text-decoration') !== false) {
            $theme['textDecoration'][$name] = $value;
        } elseif (stripos($name, 'user-select') !== false) {
            $theme['userSelect'][$name] = $value;
        } elseif (stripos($name, 'pointer-events') !== false) {
            $theme['pointerEvents'][$name] = $value;
        } elseif (stripos($name, 'box-shadow') !== false || stripos($name, 'shadow') !== false) {
            $theme['boxShadow'][$name] = $value;
        } elseif (stripos($name, 'grid-template-columns') !== false) {
            $theme['gridTemplateColumns'][$name] = $value;
        } elseif (stripos($name, 'grid-template-rows') !== false) {
            $theme['gridTemplateRows'][$name] = $value;
        } elseif (stripos($name, 'content') !== false) {
            $theme['content'][$name] = $value;
        } else {
            // Store other string variables in a generic category
            $theme['other'][$name] = $value;
        }
    }

    private function generateTailwindConfig($theme)
    {
        $configArray = [
            'content' => [
                './src/**/*.{js,jsx,ts,tsx}',
                './public/index.html',
            ],
            'theme' => [
                'extend' => $theme,
            ],
            'plugins' => [],
        ];

        return 'module.exports = '.$this->formatJsObject($configArray, 0).';';
    }

    private function formatJsObject($array, $depth = 0)
    {
        $indent = str_repeat('  ', $depth);
        $nextIndent = str_repeat('  ', $depth + 1);

        if (empty($array)) {
            return '{}';
        }

        $items = [];
        foreach ($array as $key => $value) {
            $keyStr = is_numeric($key) ? $key : "'{$key}'";

            if (is_array($value)) {
                if (empty($value)) {
                    $items[] = $nextIndent.$keyStr.': {}';
                } elseif (array_values($value) === $value) {
                    // Indexed array
                    $arrayItems = array_map(function ($item) {
                        return is_string($item) ? "'{$item}'" : json_encode($item);
                    }, $value);
                    $items[] = $nextIndent.$keyStr.': ['.implode(', ', $arrayItems).']';
                } else {
                    // Associative array
                    $items[] = $nextIndent.$keyStr.': '.$this->formatJsObject($value, $depth + 1);
                }
            } else {
                $valueStr = is_string($value) ? "'{$value}'" : json_encode($value);
                $items[] = $nextIndent.$keyStr.': '.$valueStr;
            }
        }

        return "{\n".implode(",\n", $items)."\n{$indent}}";
    }

    private function countProcessedVariables($variables)
    {
        $count = 0;

        if (isset($variables['variables']) && is_array($variables['variables'])) {
            $count = count($variables['variables']);
        } elseif (isset($variables['meta']['variables']) && is_array($variables['meta']['variables'])) {
            $count = count($variables['meta']['variables']);
        } elseif (is_array($variables)) {
            $count = count($variables);
        }

        return $count;
    }
}
