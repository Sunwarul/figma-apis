<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class BlueprintMinimizeFigmaJsonCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'blueprint:minimize-figma-json {fileKey} {nodeId}';

    /**
     * The console Blueprint minimize .
     *
     * @var string
     */
    protected $description = 'Blueprint minimize files';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $fileId = $this->argument('fileKey');
        $nodeId = $this->argument('nodeId');

        // FIX 1: Normalize the nodeId input. Figma uses a colon (:) internally, 
        // even if users provide a hyphenated ID from the URL hash.
        $normalizedNodeId = str_replace('-', ':', $nodeId);

        $this->info("Fetching data for fileKey: {$fileId} and original nodeId: {$nodeId} (using normalized ID: {$normalizedNodeId})...");

        // The IDs query parameter requires URL encoding for the colon separator.
        $encodedNodeId = urlencode($normalizedNodeId);

        $fileName = \storage_path('app/json/') . $fileId . '.json';

        if (\file_exists($fileName)) {
            $data = \file_get_contents($fileName);
            $data = json_decode($data, true);
            $originalContent = json_encode($data, JSON_PRETTY_PRINT);
            $originalSize = strlen($originalContent);

            // FIX 2: Use the normalized ID for lookup in the 'nodes' array of the JSON response.
            if (isset($data['nodes'][$normalizedNodeId]['document'])) {

                $rootNode = $data['nodes'][$normalizedNodeId]['document'];
                $attributes = $this->getAttributesToKeep();
                $processedNodes = 0;

                $this->info("Starting blueprint minimization...");

                $minimizedNode = $this->minimizeNode($rootNode, $attributes, $processedNodes);

                $minimizedContent = json_encode(['document' => $minimizedNode], JSON_PRETTY_PRINT);
                $minimizedSize = strlen($minimizedContent);

                // Use a safe file name, replacing colon with hyphen for file system
                $safeNodeId = str_replace(':', '-', $normalizedNodeId);
                $filename = "{$fileId}_clean.json";

                // Save the cleaned JSON
                Storage::put("blueprints/$filename", $minimizedContent);

                $this->info("Minimization complete. File saved to storage/app/blueprints/$filename");

                // Log output
                $this->outputFileStats($originalSize, $minimizedSize, $processedNodes);
            } else {
                $this->error("Node ID '{$normalizedNodeId}' not found or does not contain a 'document' key in the Figma response.");
                $receivedKeys = array_keys($data['nodes'] ?? []);
                $this->comment("Keys received in the 'nodes' array: " . implode(', ', $receivedKeys));
            }
        } else {
            $this->error("Failed to fetch data from file. File does not exists.}");
        }
    }

    // --- (minimizeNode, outputFileStats, formatBytes, and getAttributesToKeep methods remain unchanged) ---

    /**
     * Recursively minimizes the Figma node data.
     */
    private function minimizeNode(array $node, array $attributesToKeep, int &$processedNodes): array
    {
        $processedNodes++;
        $minimized = [];

        // Keep only the essential attributes
        foreach ($attributesToKeep as $key) {
            if (isset($node[$key])) {
                $minimized[$key] = $node[$key];
            }
        }

        // Recursively minimize children if they exist
        if (isset($minimized['children']) && is_array($minimized['children'])) {
            $minimizedChildren = [];
            foreach ($minimized['children'] as $child) {
                // Check if the child is an array before recursing
                if (is_array($child)) {
                    $minimizedChildren[] = $this->minimizeNode($child, $attributesToKeep, $processedNodes);
                }
            }
            $minimized['children'] = $minimizedChildren;
        }

        return $minimized;
    }

    /**
     * Prints the file size and node processing statistics.
     */
    private function outputFileStats(int $originalSize, int $minimizedSize, int $nodesProcessed)
    {
        $originalSizeFormatted = $this->formatBytes($originalSize);
        $minimizedSizeFormatted = $this->formatBytes($minimizedSize);

        $reduction = 0;
        if ($originalSize > 0) {
            $reduction = number_format((($originalSize - $minimizedSize) / $originalSize) * 100, 2);
        }

        $this->line("--------------------------------------------------");
        $this->comment("📝 Summary of Minimization:");
        $this->line("• Nodes Processed: " . $nodesProcessed);
        $this->line("• Original Size:   " . $originalSizeFormatted);
        $this->line("• Minimized Size:  " . $minimizedSizeFormatted);
        $this->line("• Size Reduction:  " . $reduction . "%");
        $this->line("--------------------------------------------------");
    }

    /**
     * Helper to format bytes into readable string.
     */
    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));

        return round($bytes, $precision) . ' ' . $units[$pow];
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
