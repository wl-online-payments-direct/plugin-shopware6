<?php declare(strict_types=1);

/**
 * @author Mediaopt GmbH
 * @package MoptWorldline\Service
 */

namespace MoptWorldline\Service;

use Monolog\Level;
use OnlinePayments\Sdk\Domain\PaymentProduct;
use Shopware\Core\Content\Media\File\FileSaver;
use Shopware\Core\Content\Media\File\MediaFile;
use Shopware\Core\Content\Media\MediaEntity;
use Shopware\Core\Content\Media\MediaService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;

class MediaHelper
{
    const TEMP_NAME = 'image-import-from-url';
    const MEDIA_FOLDER = 'payment_method';
    const FILE_PREFIX = 'Worldline_logo ';
    private const MIME_BY_EXTENSION = ['svg' => 'image/svg+xml', 'png' => 'image/png'];

    private EntityRepository $mediaRepository;
    private MediaService $mediaService;
    private FileSaver $fileSaver;
    private EntityRepository $paymentRepository;

    /**
     * @param EntityRepository $mediaRepository
     * @param MediaService $mediaService
     * @param FileSaver $fileSaver
     * @param EntityRepository $paymentRepository
     */
    public function __construct(
        EntityRepository $mediaRepository,
        MediaService     $mediaService,
        FileSaver        $fileSaver,
        EntityRepository $paymentRepository
    )
    {
        $this->mediaRepository = $mediaRepository;
        $this->mediaService = $mediaService;
        $this->fileSaver = $fileSaver;
        $this->paymentRepository = $paymentRepository;
    }

    /**
     * @param PaymentProduct $product
     * @param Context $context
     * @return string|null
     */
    public function createProductLogo(PaymentProduct $product, Context $context): ?string
    {
        $productData = PaymentProducts::getPaymentProductDetails($product->getId());
        if ($productData['fileName'] === PaymentProducts::PAYMENT_PRODUCT_MEDIA_DEFAULT) {
            return $this->addImageToMediaFromURL($product->getDisplayHints()->getLogo(), $context);
        }
        $logoPath = \sprintf('%s/%s.%s', PaymentProducts::getMediaSourceDir(), $productData['fileName'], $productData['extension']);
        return $this->createMediaFromFile($logoPath, $productData['fileName'], $productData['extension'], $context);
    }

    /**
     * @param string $imageUrl
     * @param Context $context
     * @return string|null
     */
    public function addImageToMediaFromURL(string $imageUrl, Context $context): ?string
    {
        $mediaId = null;

        $filePathParts = pathinfo($imageUrl);
        $fileName = self::FILE_PREFIX . $filePathParts['filename'];
        $fileExtension = $filePathParts['extension'];

        if ($fileName && $fileExtension) {
            try {
                $filePath = tempnam(sys_get_temp_dir(), self::TEMP_NAME);
                if ($filePath === false) {
                    throw new \RuntimeException('Could not create temporary file.');
                }
                $contents = @file_get_contents($imageUrl);
                if ($contents === false) {
                    throw new \RuntimeException(sprintf('Could not download logo from "%s".', $imageUrl));
                }
                if (@file_put_contents($filePath, $contents) === false) {
                    throw new \RuntimeException(sprintf('Could not write logo to "%s".', $filePath));
                }
                $mediaId = $this->createMediaFromFile($filePath, $fileName, $fileExtension, $context);
            } catch (\Throwable $e) {
                LogHelper::addLog(Level::Error, $e->getMessage());
            }
        }

        return $mediaId;
    }

    /**
     * @param string $filePath
     * @param string $fileExtension
     * @return string|null
     */
    private function resolveMimeType(string $filePath, string $fileExtension): ?string
    {
        $mimeType = mime_content_type($filePath);
        if ($mimeType !== false) {
            return $mimeType;
        }

        return self::MIME_BY_EXTENSION[strtolower($fileExtension)] ?? null;
    }

    /**
     * @param string $filePath
     * @param string $fileName
     * @param string $fileExtension
     * @param Context $context
     * @return string|null
     */
    public function createMediaFromFile(string $filePath, string $fileName, string $fileExtension, Context $context): ?string
    {
        $mediaId = null;

        try {
            if (!is_file($filePath) || !is_readable($filePath)) {
                throw new \RuntimeException(sprintf('Logo file "%s" does not exist or is not readable.', $filePath));
            }

            $fileSize = filesize($filePath);
            if ($fileSize === false) {
                throw new \RuntimeException(sprintf('Could not read metadata for logo file "%s".', $filePath));
            }
            $mimeType = $this->resolveMimeType($filePath, $fileExtension);
            if ($mimeType === null) {
                throw new \RuntimeException(sprintf('Could not resolve MIME type for logo file "%s".', $filePath));
            }

            $mediaFile = new MediaFile($filePath, $mimeType, $fileExtension, $fileSize);
            $mediaId = $this->mediaService->createMediaInFolder(self::MEDIA_FOLDER, $context, false);
            $this->fileSaver->persistFileToMedia(
                $mediaFile,
                self::FILE_PREFIX . $fileName,
                $mediaId,
                $context
            );
        } catch (\Throwable $e) {
            LogHelper::addLog(Level::Error, $e->getMessage());
            if ($mediaId !== null) {
                $this->mediaRepository->delete([['id' => $mediaId]], $context);
            }
            $mediaId = null;
        }

        return $mediaId;
    }

    /**
     * @param array $dbMethod
     * @param array $method
     * @param Context $context
     * @return string
     */
    public function getSystemMethodLogo(array $dbMethod, array $method, Context $context): string
    {
        if (array_key_exists('mediaId', $dbMethod)) {
            $mediaId = $dbMethod['mediaId'] ?: $this->createSystemLogo($method['id'], $dbMethod['internalId'], $context);
        } else {
            return '';
        }
        if (is_null($mediaId)) {
            return '';
        }
        return MediaHelper::loadLogo($mediaId, $context) ?: '';
    }

    /**
     * @param string $logoName
     * @param string $paymentMethodId
     * @param Context $context
     * @return ?string
     */
    private function createSystemLogo(string $logoName, string $paymentMethodId, Context $context): ?string
    {
        $logoPath = \sprintf('%s/%s.png', PaymentProducts::getMediaSourceDir(), $logoName);
        $mediaId = $this->createMediaFromFile($logoPath, $logoName, 'png', $context);

        $paymentMethod = [
            'id' => $paymentMethodId,
            'mediaId' => $mediaId
        ];
        $this->paymentRepository->update([$paymentMethod], $context);

        return $mediaId;
    }

    /**
     * @param string $mediaId
     * @param Context $context
     * @return string
     */
    public function loadLogo(string $mediaId, Context $context): string
    {
        $result = $this->mediaRepository->search(new Criteria([$mediaId]), $context);
        $url = '';
        /** @var MediaEntity $media */
        foreach ($result->getElements() as $media) {
            $url = $media->getUrl();
            break;
        }
        return $url;
    }

    /**
     * @param array $dbMethod
     * @param PaymentProduct $product
     * @param Context $context
     * @return string
     */
    public function getPaymentMethodLogo(array $dbMethod, PaymentProduct $product, Context $context): string
    {
        $mediaId = null;
        if (!is_null($dbMethod['mediaId'])) {
            $mediaId = $dbMethod['mediaId'];
        } elseif ($dbMethod['internalId']) {
            $mediaId = $this->createProductLogo($product, $context);
            $paymentMethod = [
                'id' => $dbMethod['internalId'],
                'mediaId' => $mediaId
            ];
            $this->paymentRepository->update([$paymentMethod], $context);
        }

        if (empty($mediaId)) {
            return $product->getDisplayHints()->getLogo();
        }
        return $this->loadLogo($mediaId, $context);
    }
}