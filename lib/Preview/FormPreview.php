<?php

declare(strict_types=1);

namespace OCA\FormVox\Preview;

use OCP\Files\File;
use OCP\Files\FileInfo;
use OCP\IImage;
use OCP\Image;
use OCP\Preview\IProviderV2;
use OCA\FormVox\AppInfo\Application;

class FormPreview implements IProviderV2
{
    /**
     * @inheritDoc
     */
    public function getMimeType(): string
    {
        return Application::MIME_TYPE;
    }

    /**
     * @inheritDoc
     */
    public function isAvailable(FileInfo $file): bool
    {
        return $file->getExtension() === Application::FILE_EXTENSION;
    }

    /**
     * @inheritDoc
     */
    public function getThumbnail(File $file, int $maxX, int $maxY): ?IImage
    {
        try {
            $content = $file->getContent();
            $form = json_decode($content, true);

            if ($form === null) {
                return null;
            }

            // Create image
            $image = imagecreatetruecolor($maxX, $maxY);
            if ($image === false) {
                return null;
            }

            // Colors
            $bgColor = imagecolorallocate($image, 255, 255, 255);
            $primaryColor = imagecolorallocate($image, 0, 130, 201);
            $textColor = imagecolorallocate($image, 51, 51, 51);
            $mutedColor = imagecolorallocate($image, 128, 128, 128);

            // Fill background
            imagefill($image, 0, 0, $bgColor);

            // Draw form icon (simple form representation)
            $iconSize = min($maxX, $maxY) * 0.4;
            $iconX = ($maxX - $iconSize) / 2;
            $iconY = $maxY * 0.15;

            // Draw form rectangle
            imagefilledrectangle(
                $image,
                (int)$iconX,
                (int)$iconY,
                (int)($iconX + $iconSize),
                (int)($iconY + $iconSize),
                $primaryColor
            );

            // Draw lines to represent form fields
            $lineY = $iconY + $iconSize * 0.2;
            $lineHeight = $iconSize * 0.15;
            $lineSpacing = $iconSize * 0.25;

            for ($i = 0; $i < 3; $i++) {
                imagefilledrectangle(
                    $image,
                    (int)($iconX + $iconSize * 0.1),
                    (int)$lineY,
                    (int)($iconX + $iconSize * 0.9),
                    (int)($lineY + $lineHeight),
                    $bgColor
                );
                $lineY += $lineSpacing;
            }

            // Draw title text
            $title = $form['title'] ?? 'Form';
            if (strlen($title) > 20) {
                $title = substr($title, 0, 17) . '...';
            }

            $fontSize = 3;
            $textWidth = imagefontwidth($fontSize) * strlen($title);
            $textX = ($maxX - $textWidth) / 2;
            $textY = $iconY + $iconSize + $maxY * 0.1;

            imagestring($image, $fontSize, (int)$textX, (int)$textY, $title, $textColor);

            // Draw question count
            $questionCount = count($form['questions'] ?? []);
            $responseCount = $form['_index']['response_count'] ?? count($form['responses'] ?? []);
            $stats = "{$questionCount} questions, {$responseCount} responses";

            $statsWidth = imagefontwidth(2) * strlen($stats);
            $statsX = ($maxX - $statsWidth) / 2;
            $statsY = $textY + $maxY * 0.1;

            imagestring($image, 2, (int)$statsX, (int)$statsY, $stats, $mutedColor);

            // Convert to IImage
            ob_start();
            imagepng($image);
            $data = ob_get_clean();
            imagedestroy($image);

            $img = new Image();
            $img->loadFromData($data);

            return $img;
        } catch (\Exception $e) {
            return null;
        }
    }
}
