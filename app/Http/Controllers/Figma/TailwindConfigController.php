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
        $url = "https://api.figma.com/v1/files/{$fileKey}/variables/local";
        $response = Http::withHeaders([
            'x-figma-token' => $figmaToken,
        ])->timeout(60 * 5)->get($url);

        if (! $response->successful()) {
            Log::error('Figma API error', ['status' => $response->status(), 'body' => $response->body()]);

            return Response::json(['error' => 'Failed to fetch Figma variables'], 400);
        }
        $variables = $response->json();
        // file_put_contents('variables_'.time().'.json', json_encode($variables));
        $theme = $this->mapFigmaVariablesToTailwindTheme($variables);
        $configJs = 'module.exports = { theme: '.json_encode($theme, JSON_PRETTY_PRINT).' };';

        // file_put_contents('config_js_'.time().'.json', json_encode($configJs));

        return Response::json(['config' => $configJs]);
    }

    private function mapFigmaVariablesToTailwindTheme($variables)
    {
        // Map Figma variables to Tailwind theme structure
        $theme = [
            'colors' => [],
            'spacing' => [],
            'fontSize' => [],
            'borderRadius' => [],
            'opacity' => [],
            'zIndex' => [],
            'fontWeight' => [],
            'lineHeight' => [],
            'letterSpacing' => [],
            'width' => [],
            'height' => [],
            'minWidth' => [],
            'minHeight' => [],
            'maxWidth' => [],
            'maxHeight' => [],
            'boxShadow' => [],
            'transitionDuration' => [],
            'transitionTimingFunction' => [],
            'transitionDelay' => [],
            'backgroundImage' => [],
            'backgroundSize' => [],
            'backgroundPosition' => [],
            'backgroundColor' => [],
            'borderColor' => [],
            'borderWidth' => [],
            'borderStyle' => [],
            'outlineWidth' => [],
            'outlineColor' => [],
            'outlineOffset' => [],
            'cursor' => [],
            'flex' => [],
            'flexGrow' => [],
            'flexShrink' => [],
            'order' => [],
            'gridTemplateColumns' => [],
            'gridColumn' => [],
            'gridTemplateRows' => [],
            'gridRow' => [],
            'gap' => [],
            'inset' => [],
            'aspectRatio' => [],
        ];

        if (isset($variables['variables']) && is_array($variables['variables'])) {
            foreach ($variables['variables'] as $var) {
                if (! isset($var['name'], $var['resolvedType'], $var['resolvedValue'])) {
                    continue;
                }
                $name = $var['name'];
                $type = $var['resolvedType'];
                $value = $var['resolvedValue'];

                switch ($type) {
                    case 'COLOR':
                        // Accept hex, rgb, rgba, hsl
                        if (is_string($value) && preg_match('/^#([A-Fa-f0-9]{3,8})$/', $value)) {
                            $theme['colors'][$name] = $value;
                            $theme['backgroundColor'][$name] = $value;
                            $theme['borderColor'][$name] = $value;
                        }
                        break;
                    case 'FLOAT':
                    case 'NUMBER':
                        // Map to spacing, fontSize, etc. by variable name
                        if (stripos($name, 'spacing') !== false) {
                            $theme['spacing'][$name] = $value;
                        } elseif (stripos($name, 'font-size') !== false || stripos($name, 'fontsize') !== false) {
                            $theme['fontSize'][$name] = $value;
                        } elseif (stripos($name, 'radius') !== false) {
                            $theme['borderRadius'][$name] = $value;
                        } elseif (stripos($name, 'opacity') !== false) {
                            $theme['opacity'][$name] = $value;
                        } elseif (stripos($name, 'z-index') !== false || stripos($name, 'zindex') !== false) {
                            $theme['zIndex'][$name] = $value;
                        } elseif (stripos($name, 'font-weight') !== false || stripos($name, 'fontweight') !== false) {
                            $theme['fontWeight'][$name] = $value;
                        } elseif (stripos($name, 'line-height') !== false || stripos($name, 'lineheight') !== false) {
                            $theme['lineHeight'][$name] = $value;
                        } elseif (stripos($name, 'letter-spacing') !== false || stripos($name, 'letterspacing') !== false) {
                            $theme['letterSpacing'][$name] = $value;
                        } elseif (stripos($name, 'width') !== false) {
                            $theme['width'][$name] = $value;
                        } elseif (stripos($name, 'height') !== false) {
                            $theme['height'][$name] = $value;
                        } elseif (stripos($name, 'min-width') !== false) {
                            $theme['minWidth'][$name] = $value;
                        } elseif (stripos($name, 'min-height') !== false) {
                            $theme['minHeight'][$name] = $value;
                        } elseif (stripos($name, 'max-width') !== false) {
                            $theme['maxWidth'][$name] = $value;
                        } elseif (stripos($name, 'max-height') !== false) {
                            $theme['maxHeight'][$name] = $value;
                        } elseif (stripos($name, 'box-shadow') !== false || stripos($name, 'shadow') !== false) {
                            $theme['boxShadow'][$name] = $value;
                        } elseif (stripos($name, 'transition-duration') !== false) {
                            $theme['transitionDuration'][$name] = $value;
                        } elseif (stripos($name, 'transition-timing-function') !== false) {
                            $theme['transitionTimingFunction'][$name] = $value;
                        } elseif (stripos($name, 'transition-delay') !== false) {
                            $theme['transitionDelay'][$name] = $value;
                        } elseif (stripos($name, 'background-size') !== false) {
                            $theme['backgroundSize'][$name] = $value;
                        } elseif (stripos($name, 'background-position') !== false) {
                            $theme['backgroundPosition'][$name] = $value;
                        } elseif (stripos($name, 'background-image') !== false) {
                            $theme['backgroundImage'][$name] = $value;
                        } elseif (stripos($name, 'border-width') !== false) {
                            $theme['borderWidth'][$name] = $value;
                        } elseif (stripos($name, 'border-style') !== false) {
                            $theme['borderStyle'][$name] = $value;
                        } elseif (stripos($name, 'outline-width') !== false) {
                            $theme['outlineWidth'][$name] = $value;
                        } elseif (stripos($name, 'outline-color') !== false) {
                            $theme['outlineColor'][$name] = $value;
                        } elseif (stripos($name, 'outline-offset') !== false) {
                            $theme['outlineOffset'][$name] = $value;
                        } elseif (stripos($name, 'cursor') !== false) {
                            $theme['cursor'][$name] = $value;
                        } elseif (stripos($name, 'flex') !== false) {
                            $theme['flex'][$name] = $value;
                        } elseif (stripos($name, 'flex-grow') !== false) {
                            $theme['flexGrow'][$name] = $value;
                        } elseif (stripos($name, 'flex-shrink') !== false) {
                            $theme['flexShrink'][$name] = $value;
                        } elseif (stripos($name, 'order') !== false) {
                            $theme['order'][$name] = $value;
                        } elseif (stripos($name, 'grid-template-columns') !== false) {
                            $theme['gridTemplateColumns'][$name] = $value;
                        } elseif (stripos($name, 'grid-column') !== false) {
                            $theme['gridColumn'][$name] = $value;
                        } elseif (stripos($name, 'grid-template-rows') !== false) {
                            $theme['gridTemplateRows'][$name] = $value;
                        } elseif (stripos($name, 'grid-row') !== false) {
                            $theme['gridRow'][$name] = $value;
                        } elseif (stripos($name, 'gap') !== false) {
                            $theme['gap'][$name] = $value;
                        } elseif (stripos($name, 'inset') !== false) {
                            $theme['inset'][$name] = $value;
                        } elseif (stripos($name, 'aspect-ratio') !== false) {
                            $theme['aspectRatio'][$name] = $value;
                        }
                        break;
                    case 'STRING':
                        // Map string variables to theme keys by name
                        if (stripos($name, 'font-family') !== false) {
                            $theme['fontFamily'][$name] = $value;
                        } elseif (stripos($name, 'background-image') !== false) {
                            $theme['backgroundImage'][$name] = $value;
                        } elseif (stripos($name, 'cursor') !== false) {
                            $theme['cursor'][$name] = $value;
                        }
                        break;
                    case 'BOOLEAN':
                        // Map boolean variables to theme keys by name
                        $theme['boolean'][$name] = $value;
                        break;
                    default:
                        // Store unknown types in a generic key
                        $theme['other'][$name] = $value;
                        break;
                }
            }
        }

        // Remove empty arrays for cleaner output
        foreach ($theme as $key => $val) {
            if (is_array($val) && empty($val)) {
                unset($theme[$key]);
            }
        }

        return $theme;
    }
}
