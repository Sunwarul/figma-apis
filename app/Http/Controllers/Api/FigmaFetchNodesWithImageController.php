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
     *             required={"fileKey", "nodeIds"},
     *
     *             @OA\Property(property="fileKey", type="string", example="6uZ8mS1F4Oi7aL0VJLau49"),
     *             @OA\Property(property="nodeIds", type="string", example="13191-107467"),
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="JSON Response with node and image URLs",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="nodes", type="array", @OA\Items(type="object")),
     *             @OA\Property(property="message", type="string", example="Successfully processed nodes with images"),
     *             @OA\Property(property="images_processed", type="integer", example=5)
     *         )
     *     ),
     *
     *     @OA\Response(response=400, description="Bad request"),
     *     @OA\Response(response=500, description="Server error")
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
        $normalizedNodeIds = array_map(function ($id) {
            return str_replace('-', ':', $id);
        }, $nodeIds);

        // Step 1: Fetch all image fills from the file
        Log::info('Fetching image fills from Figma', ['fileKey' => $fileKey]);
        $imageFills = $this->fetchImageFills($fileKey, $figmaToken);
        Log::info('Image fills fetched', ['count' => count($imageFills)]);

        // Step 2: Download and store all images locally
        $imageUrlMap = $this->downloadAllImages($imageFills, $fileKey);
        Log::info('Images downloaded and stored', ['count' => count($imageUrlMap)]);

        // Step 3: Fetch node data
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
        $imagesProcessed = 0;

        // Step 4: Process nodes and inject image URLs
        foreach ($normalizedNodeIds as $normalizedNodeId) {
            if (!isset($data['nodes'][$normalizedNodeId]['document'])) {
                Log::warning("Node ID '{$normalizedNodeId}' not found in Figma response.");
                continue;
            }
            $rootNode = $data['nodes'][$normalizedNodeId]['document'];
            $processedNodes = 0;
            $minimizedNode = $this->minimizeNodeWithImages($rootNode, $attributes, $processedNodes, $imageUrlMap, $imagesProcessed);
            $result[] = $minimizedNode;
        }

        return response()->json([
            'nodes' => $result,
            'message' => 'Successfully processed nodes with images',
            'images_processed' => $imagesProcessed
        ]);
    }

    /**
     * Fetch all image fills from Figma file
     * GET /v1/files/:key/images
     */
    private function fetchImageFills($fileKey, $figmaToken): array
    {
        $url = "https://api.figma.com/v1/files/{$fileKey}/images";

        $response = Http::withHeaders([
            'x-figma-token' => $figmaToken,
        ])->timeout(60)->get($url);

        if (!$response->successful()) {
            Log::error('Failed to fetch image fills', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            return [];
        }

        $json = $response->json();

        if (!isset($json['images']) || empty($json['images'])) {
            Log::info('No images found in file');
            return [];
        }

        // Return mapping of imageRef => downloadUrl
        return $json['images'];
    }

    /**
     * Download all images and store them locally
     */
    private function downloadAllImages(array $imageFills, $fileKey): array
    {
        $imageUrlMap = [];

        if (empty($imageFills)) {
            return $imageUrlMap;
        }

        // Ensure the figma directory exists
        $figmaDir = public_path('figma');
        if (!file_exists($figmaDir)) {
            mkdir($figmaDir, 0755, true);
            Log::info('Created figma directory', ['path' => $figmaDir]);
        }

        foreach ($imageFills as $imageRef => $downloadUrl) {
            if (!$downloadUrl) {
                Log::warning("Empty download URL for imageRef: {$imageRef}");
                continue;
            }

            try {
                Log::info('Downloading image', ['imageRef' => $imageRef, 'url' => $downloadUrl]);

                // Download image from Figma
                $imageResponse = Http::timeout(60)->get($downloadUrl);

                if (!$imageResponse->successful()) {
                    Log::error('Failed to download image', [
                        'imageRef' => $imageRef,
                        'status' => $imageResponse->status(),
                    ]);
                    continue;
                }

                // Detect image format from content-type or URL
                $contentType = $imageResponse->header('Content-Type');
                $ext = 'png'; // default

                if (strpos($contentType, 'jpeg') !== false || strpos($contentType, 'jpg') !== false) {
                    $ext = 'jpg';
                } elseif (strpos($contentType, 'png') !== false) {
                    $ext = 'png';
                } elseif (strpos($contentType, 'gif') !== false) {
                    $ext = 'gif';
                } elseif (strpos($contentType, 'webp') !== false) {
                    $ext = 'webp';
                }

                // Create safe filename from imageRef
                $safeImageRef = preg_replace('/[^a-zA-Z0-9_-]/', '_', $imageRef);
                $filename = "{$fileKey}_{$safeImageRef}.{$ext}";
                $publicPath = public_path("figma/{$filename}");

                // Save image
                file_put_contents($publicPath, $imageResponse->body());

                // Store the public URL mapped to imageRef
                $publicUrl = url("/figma/{$filename}");
                $imageUrlMap[$imageRef] = $publicUrl;

                Log::info('Image stored successfully', [
                    'imageRef' => $imageRef,
                    'path' => $publicPath,
                    'url' => $publicUrl
                ]);
            } catch (\Exception $e) {
                Log::error('Exception while downloading/storing image', [
                    'imageRef' => $imageRef,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        }

        return $imageUrlMap;
    }

    /**
     * Check if a node contains image fills
     */
    private function hasImageFill(array $node): ?string
    {
        // Check if node has fills property
        if (!isset($node['fills']) || !is_array($node['fills'])) {
            return null;
        }

        // Look for IMAGE type fill with imageRef
        foreach ($node['fills'] as $fill) {
            if (isset($fill['type']) && $fill['type'] === 'IMAGE' && isset($fill['imageRef'])) {
                return $fill['imageRef'];
            }
        }

        return null;
    }

    /**
     * Minimizes node and adds image_url for nodes with image fills
     */
    private function minimizeNodeWithImages(array $node, array $attributesToKeep, int &$processedNodes, array $imageUrlMap, int &$imagesProcessed): array
    {
        $processedNodes++;
        $minimized = [];

        // Keep only essential attributes
        foreach ($attributesToKeep as $key) {
            if (isset($node[$key])) {
                $minimized[$key] = $node[$key];
            }
        }

        // Check if this node has an image fill
        $imageRef = $this->hasImageFill($node);

        if ($imageRef && isset($imageUrlMap[$imageRef])) {
            // Add the local image URL to this node
            $minimized['image_url'] = $imageUrlMap[$imageRef];
            $imagesProcessed++;

            Log::info('Added image_url to node', [
                'node_id' => $minimized['id'] ?? 'unknown',
                'node_name' => $minimized['name'] ?? 'unknown',
                'imageRef' => $imageRef,
                'image_url' => $minimized['image_url']
            ]);
        }

        // Recursively minimize children
        if (isset($minimized['children']) && is_array($minimized['children'])) {
            $minimizedChildren = [];
            foreach ($minimized['children'] as $child) {
                if (is_array($child)) {
                    $minimizedChildren[] = $this->minimizeNodeWithImages($child, $attributesToKeep, $processedNodes, $imageUrlMap, $imagesProcessed);
                }
            }
            $minimized['children'] = $minimizedChildren;
        }

        return $minimized;
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
