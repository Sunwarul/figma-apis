<?php

namespace App\Http\Controllers\Api;

/**
 * @OA\Info(
 *     version="1.0.0",
 *     title="Figma APIs",
 *     description="API for converting Figma variables to Tailwind config"
 * )
 */
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class FigmaFetchNodesWithImageController extends Controller
{

    /**
     * @OA\Post(
     *     path="/api/figma/fetch-nodes-with-image",
     *     summary="Generate node and image URLs for Figma nodes",
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
     *         description="JSON Response with node and image URLs",
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
            'nodeIds' => 'required', // Accepts string or array
        ]);

        $fileKey = $request->input('fileKey');
        $nodeIds = $request->input('nodeIds');
        $figmaToken = env('FIGMA_TOKEN');

        if (empty($figmaToken)) {
            Log::error('Figma token not configured');
            return response()->json(['error' => 'Figma token not configured'], 500);
        }

        // Support both string and array for nodeIds
        if (is_string($nodeIds)) {
            $nodeIds = [$nodeIds];
        }

        // Normalize node IDs (Figma uses colon internally)
        $normalizedNodeIds = array_map(function($id) {
            return str_replace('-', ':', $id);
        }, $nodeIds);

        $encodedNodeIds = array_map('urlencode', $normalizedNodeIds);
        $idsParam = implode(',', $encodedNodeIds);
        $url = "https://api.figma.com/v1/files/{$fileKey}/nodes?ids={$idsParam}";

        $response = Http::withHeaders([
            'x-figma-token' => $figmaToken,
        ])->timeout(60)->get($url);

        if (!$response->successful()) {
            Log::error('Figma API error', [
                'status' => $response->status(),
                'body' => $response->body(),
                'url' => $url,
            ]);
            return response()->json([
                'error' => 'Failed to fetch Figma nodes',
                'details' => $response->status() === 404 ? 'File not found or no access' : 'API request failed',
            ], $response->status());
        }

        $data = $response->json();
        $attributes = $this->getAttributesToKeep();
        $result = [];

        foreach ($normalizedNodeIds as $normalizedNodeId) {
            if (!isset($data['nodes'][$normalizedNodeId]['document'])) {
                Log::warning("Node ID '{$normalizedNodeId}' not found in Figma response.");
                continue;
            }
            $rootNode = $data['nodes'][$normalizedNodeId]['document'];
            $processedNodes = 0;
            $minimizedNode = $this->minimizeNodeWithImages($rootNode, $attributes, $processedNodes, $fileKey);
            $result[] = $minimizedNode;
        }

        return response()->json(['nodes' => $result]);
    }

    // --- (minimizeNode, outputFileStats, formatBytes, and getAttributesToKeep methods remain unchanged) ---

    /**
     * Recursively minimizes the Figma node data.
     */
    private function minimizeNode(array $node, array $attributesToKeep, int &$processedNodes): array
    {
        // Legacy minimization (no image handling)
        $processedNodes++;
        $minimized = [];
        foreach ($attributesToKeep as $key) {
            if (isset($node[$key])) {
                $minimized[$key] = $node[$key];
            }
        }
        if (isset($minimized['children']) && is_array($minimized['children'])) {
            $minimizedChildren = [];
            foreach ($minimized['children'] as $child) {
                if (is_array($child)) {
                    $minimizedChildren[] = $this->minimizeNode($child, $attributesToKeep, $processedNodes);
                }
            }
            $minimized['children'] = $minimizedChildren;
        }
        return $minimized;
    }

    /**
     * Minimizes node and adds image_url for image nodes.
     */
    private function minimizeNodeWithImages(array $node, array $attributesToKeep, int &$processedNodes, $fileKey): array
    {
        $processedNodes++;
        $minimized = [];
        foreach ($attributesToKeep as $key) {
            if (isset($node[$key])) {
                $minimized[$key] = $node[$key];
            }
        }
        // If node is an image, fetch and store image
        if (isset($minimized['type']) && strtoupper($minimized['type']) === 'IMAGE') {
            $imageUrl = $this->fetchAndStoreFigmaImage($fileKey, $minimized['id']);
            if ($imageUrl) {
                $minimized['image_url'] = $imageUrl;
            }
        }
        // Recursively minimize children
        if (isset($minimized['children']) && is_array($minimized['children'])) {
            $minimizedChildren = [];
            foreach ($minimized['children'] as $child) {
                if (is_array($child)) {
                    $minimizedChildren[] = $this->minimizeNodeWithImages($child, $attributesToKeep, $processedNodes, $fileKey);
                }
            }
            $minimized['children'] = $minimizedChildren;
        }
        return $minimized;
    }

    /**
     * Fetches image from Figma and stores in public storage, returns public URL.
     */
    private function fetchAndStoreFigmaImage($fileKey, $nodeId)
    {
        $figmaToken = env('FIGMA_TOKEN');
        $url = "https://api.figma.com/v1/images/{$fileKey}?ids=" . urlencode($nodeId);
        $response = Http::withHeaders([
            'x-figma-token' => $figmaToken,
        ])->timeout(60)->get($url);
        if (!$response->successful()) {
            Log::error('Failed to fetch Figma image', [
                'status' => $response->status(),
                'body' => $response->body(),
                'url' => $url,
            ]);
            return null;
        }
        $json = $response->json();
        if (!isset($json['images'][$nodeId])) {
            Log::warning("No image URL for node {$nodeId}");
            return null;
        }
        $remoteImageUrl = $json['images'][$nodeId];
        // Download image
        $imageResponse = Http::timeout(60)->get($remoteImageUrl);
        if (!$imageResponse->successful()) {
            Log::error('Failed to download image from Figma', [
                'status' => $imageResponse->status(),
                'url' => $remoteImageUrl,
            ]);
            return null;
        }
        $ext = 'png'; // Figma default
        $filename = "figma/{$fileKey}_{$nodeId}.{$ext}";
        Storage::disk('public')->put($filename, $imageResponse->body());
        return asset("storage/{$filename}");
    }

    /**
     * Defines the list of essential attributes to keep in the minimized JSON.
     */
    private function getAttributesToKeep(): array
    {
        return [
            'id',
            'name',
            'type',
            'children',
            'characters',
            'style',
            'absoluteBoundingBox',
            'layoutMode',
            'layoutSizingHorizontal',
            'layoutSizingVertical',
            'itemSpacing',
            'paddingLeft',
            'paddingRight',
            'paddingTop',
            'paddingBottom',
            'primaryAxisAlignItems',
            'counterAxisAlignItems',
            'clipsContent',
            'cornerRadius',
            'fills',
            'strokes',
            'strokeWeight',
            'effects',
            'opacity',
            'componentId',
            'exportSettings',
            'boundVariables',
        ];
    }
}
