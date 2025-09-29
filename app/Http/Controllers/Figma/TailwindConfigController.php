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

        return Response::json(['config' => $configJs]);
    }

    private function mapFigmaVariablesToTailwindTheme($variables)
    {
        // Example: Map Figma variables to Tailwind theme structure
        $theme = [
            'colors' => [],
        ];
        if (isset($variables['variables']) && is_array($variables['variables'])) {
            foreach ($variables['variables'] as $var) {
                if (isset($var['name'], $var['resolvedValue'])) {
                    // Only map color variables (hex, rgb, etc.)
                    $name = $var['name'];
                    $value = $var['resolvedValue'];
                    if (is_string($value) && preg_match('/^#([A-Fa-f0-9]{3,8})$/', $value)) {
                        $theme['colors'][$name] = $value;
                    }
                }
            }
        }

        return $theme;
    }
}
