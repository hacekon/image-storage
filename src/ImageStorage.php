<?php declare(strict_types = 1);

namespace Contributte\ImageStorage;

use Contributte\ImageStorage\Exception\ImageExtensionException;
use Contributte\ImageStorage\Exception\ImageResizeException;
use Contributte\ImageStorage\Exception\ImageStorageException;
use DirectoryIterator;
use Nette\Http\FileUpload;
use Nette\SmartObject;
use Nette\Utils\FileSystem;
use Nette\Utils\Image as NetteImage;
use Nette\Utils\Strings;
use Nette\Utils\UnknownImageFileException;

class ImageStorage
{

	use SmartObject;

	private string $data_path;

	private string $data_dir;

	private string $orig_path;

	/** @var callable(string): string */
	private $algorithm_file;

	/** @var callable(string): string */
	private $algorithm_content;

	/** @var array<string, int|null> */
	private array $quality;

	private string $default_transform;

	private string $noimage_identifier;

	private bool $friendly_url;

	private int $mask = 0775;

	/** @var int[] */
	private array $_image_flags = [
		'fit' => 0,
		'fill' => 4,
		'exact' => 8,
		'stretch' => 2,
		'shrink_only' => 1,
	];

	/**
	 * @param callable(string): string $algorithm_file
	 * @param callable(string): string $algorithm_content
	 * @param array<string, int|null> $quality Format-specific quality settings (jpeg, png, webp, avif, gif)
	 */
	public function __construct(
		string $data_path,
		string $data_dir,
		string $orig_path,
		callable $algorithm_file,
		callable $algorithm_content,
		array $quality,
		string $default_transform,
		string $noimage_identifier,
		bool $friendly_url
	)
	{
		$this->data_path = $data_path;
		$this->data_dir = $data_dir;
		$this->orig_path = $orig_path;
		$this->algorithm_file = $algorithm_file;
		$this->algorithm_content = $algorithm_content;
		$this->quality = $quality;
		$this->default_transform = $default_transform;
		$this->noimage_identifier = $noimage_identifier;
		$this->friendly_url = $friendly_url;
	}

	public function delete(mixed $arg, bool $onlyChangedImages = false): void
	{
		$script = is_object($arg) && $arg instanceof Image
			? ImageNameScript::fromIdentifier($arg->identifier)
			: ImageNameScript::fromName($arg);

		$pattern = preg_replace('/__file__/', $script->name, ImageNameScript::PATTERN);
		$dir = implode('/', [$this->data_path, $script->namespace, $script->prefix]);
		$origFile = $script->name . '.' . $script->extension;

		if ($this->orig_path === $this->data_path) {
			if (!file_exists($dir)) {
				return;
			}

			foreach (new DirectoryIterator($dir) as $file_info) {
				if (
					!preg_match($pattern, $file_info->getFilename())
					|| !(!$onlyChangedImages || $origFile !== $file_info->getFilename()
					)
				) {
					continue;
				}

				unlink($file_info->getPathname());
			}
		} else {
			if (!$onlyChangedImages) {
				unlink(implode('/', [$this->orig_path, $script->namespace, $script->prefix, $origFile]));
			}

			FileSystem::delete($dir);
		}
	}

	public function saveUpload(FileUpload $upload, string $namespace, ?string $checksum = null): Image
	{
		if (!$checksum) {
			$checksum = call_user_func_array($this->algorithm_file, [$upload->getTemporaryFile()]);
		}

		[$path, $identifier] = $this->getSavePath(
			self::fixName($upload->getUntrustedName()),
			$namespace,
			$checksum
		);

		$upload->move($path);

		return new Image($this->friendly_url, $this->data_dir, $this->data_path, $identifier, [
			'sha' => $checksum,
			'name' => self::fixName($upload->getUntrustedName()),
		]);
	}

	public function saveContent(mixed $content, string $name, string $namespace, ?string $checksum = null): Image
	{
		if (!$checksum) {
			$checksum = call_user_func_array($this->algorithm_content, [$content]);
		}

		[$path, $identifier] = $this->getSavePath(
			self::fixName($name),
			$namespace,
			$checksum
		);

		file_put_contents($path, $content, LOCK_EX);

		return new Image($this->friendly_url, $this->data_dir, $this->data_path, $identifier, [
			'sha' => $checksum,
			'name' => self::fixName($name),
		]);
	}

	public function fromIdentifier(mixed $args): Image
	{

		if (!is_array($args)) {
			$args = [$args];
		}

		// DEBUG: uncomment to see what arguments are received
		// dump('fromIdentifier received:', $args);

		// Unwrap if arguments are wrapped in array (Latte parser behavior)
		if (count($args) === 1 && is_array($args[0])) {
			// Check if it's an associative array with 'path' key or has any string keys
			$firstElement = $args[0];
			if (isset($firstElement['path']) || (is_array($firstElement) && array_keys($firstElement) !== range(0, count($firstElement) - 1))) {
				$args = $args[0];
			}
		}

		// Support both associative array with named keys and positional array
		$isAssociative = array_keys($args) !== range(0, count($args) - 1);

		if ($isAssociative) {
			$identifier = $args['path'] ?? $args[0] ?? null;
			$size = $args['size'] ?? null;
			$flag = $args['flag'] ?? $this->default_transform;
			$qualityOverride = $args['quality'] ?? null;
			$convertToWebp = $args['convertToWebp'] ?? true; // Default: convert to WEBP

			// Ensure identifier is a string, not an array
			if (is_array($identifier)) {
				$identifier = $identifier[0] ?? null;
			}

			// Convert to positional array format for backward compatibility
			$args = [$identifier, $size, $flag, $qualityOverride, $convertToWebp];
		} else {
			$identifier = $args[0];
			$size = $args[1] ?? null;
			$flag = $args[2] ?? $this->default_transform;
			$qualityOverride = $args[3] ?? null;
			$convertToWebp = $args[4] ?? true; // Default: convert to WEBP

			// Ensure identifier is a string, not an array
			if (is_array($identifier)) {
				$identifier = $identifier[0] ?? null;
			}

			// Standardize to consistent positional array format
			$args = [$identifier, $size, $flag, $qualityOverride, $convertToWebp];
		}

		$orig_file = implode('/', [$this->orig_path, $identifier]);
		$data_file = implode('/', [$this->data_path, $identifier]);
		$isNoImage = false;

		// Return original image if no size is specified
		if (count($args) === 1 || empty($args[1])) {
			if (!file_exists($orig_file) || !$identifier) {
				return $this->getNoImage(true);
			}

			if (!file_exists($data_file)) {
				@mkdir(dirname($data_file), $this->mask, true);
				@copy($orig_file, $data_file);
			}

			return new Image($this->friendly_url, $this->data_dir, $this->data_path, $identifier);
		}

		preg_match('/(\d+)?x(\d+)?(crop(\d+)x(\d+)x(\d+)x(\d+))?/', $args[1], $matches);

		// Validate size format
		if (!isset($matches[1]) || !isset($matches[2]) || empty($matches[1]) || empty($matches[2])) {
			$invalidSize = $args[1] ?? 'null';
			throw new ImageResizeException(
				"Invalid image size format: '{$invalidSize}'\n" .
				"Expected format: 'WIDTHxHEIGHT' (e.g., '800x600')\n\n" .
				"Correct usage:\n" .
				"  n:img=\"\$image->getPath(), '800x600'\"\n" .
				"  n:img=\"\$image->getPath(), ['400', '800', '1200']\"\n" .
				"  n:img=\"\$image->getPath(), ['1200x537', '800', '400'], 'fill'\"\n" .
				"  {imgLink \$image->getPath(), '800x600', 'fit'}\n\n" .
				"Note: In srcset arrays, you can use width-only (e.g., '400') - height is auto-calculated.\n" .
				"      Original image must exist at: {$this->orig_path}/{$identifier}"
			);
		}

		$size = [(int) $matches[1], (int) $matches[2]];
		$crop = [];

		if (!$size[0] || !$size[1]) {
			throw new ImageResizeException(
				"Error resizing image. You have to provide both width and height.\n\n" .
				"Correct format: 'WIDTHxHEIGHT' (e.g., '800x600')\n" .
				"Received: '{$args[1]}' which parsed as width={$size[0]}, height={$size[1]}"
			);
		}

		if (count($matches) === 8) {
			$crop = [(int) $matches[4], (int) $matches[5], (int) $matches[6], (int) $matches[7]];
		}

		if (!$identifier) {
			$isNoImage = false;
			[$script, $file] = $this->getNoImage(false);
		} else {
			$script = ImageNameScript::fromIdentifier($identifier);

			$file = $orig_file;

			if (!file_exists($file)) {
				$isNoImage = true;
				[$script, $file] = $this->getNoImage(false);
			}
		}

		$script->setSize($size);
		$script->setCrop($crop);
		$script->setFlag($flag);

		// Convert to WEBP if enabled and source is JPG/PNG
		if ($convertToWebp && !$isNoImage) {
			$originalExt = strtolower($script->extension);
			if (in_array($originalExt, ['jpg', 'jpeg', 'png'], true)) {
				$script->setExtension('webp');
			}
		}

		// Get quality: use override if provided, otherwise get format-specific default
		$quality = $qualityOverride ?? $this->getQualityForFormat($script->extension);
		$script->setQuality($quality);

		$identifier = $script->getIdentifier();
		$data_file = implode('/', [$this->data_path, $identifier]);

		if (!file_exists($data_file)) {
			if (!file_exists($file)) {
				return new Image(false, '#', '#', 'Can not find image');
			}

			try {
				$_image = NetteImage::fromFile($file);
			} catch (UnknownImageFileException $e) {
				return new Image(false, '#', '#', 'Unknown type of file');
			}

			if ($script->hasCrop() && !$isNoImage) {
				call_user_func_array([$_image, 'crop'], $script->crop);
			}

			if (strpos($flag, '+') !== false) {
				$bits = 0;

				foreach (explode('+', $flag) as $f) {
					$bits = $this->_image_flags[$f] | $bits;
				}

				$flag = $bits;
			} else {
				$flag = $this->_image_flags[$flag];
			}

			$_image->resize($size[0], $size[1], $flag);

			@mkdir(dirname($data_file), $this->mask, true);
			$_image->sharpen()->save(
				$data_file,
				$quality
			);
		}

		return new Image($this->friendly_url, $this->data_dir, $this->data_path, $identifier, ['script' => $script]);
	}

	/**
	 * @return Image|mixed[]
	 * @phpstan-return Image|array{ImageNameScript, string}
	 * @throws ImageStorageException
	 */
	public function getNoImage(bool $return_image = false): Image|array
	{
		$script = ImageNameScript::fromIdentifier($this->noimage_identifier);
		$file = implode('/', [$this->data_path, $script->original]);

		if (!file_exists($file)) {
			$identifier = $this->noimage_identifier;
			$new_path = sprintf('%s/%s', $this->data_path, $identifier);

			if (!file_exists($new_path)) {
				$dirName = dirname($new_path);

				if (!file_exists($dirName)) {
					mkdir($dirName, 0777, true);
				}

				if (!file_exists($dirName) || !is_writable($dirName)) {
					throw new ImageStorageException('Could not create default no_image.png. ' . $dirName . ' does not exist or is not writable.');
				}

				$data = base64_decode(require __DIR__ . '/NoImageSource.php', true);
				$_image = NetteImage::fromString($data);
				$noImageQuality = $script->quality ?: $this->getQualityForFormat($script->extension);
				$_image->save($new_path, $noImageQuality);
			}

			if ($return_image) {
				return new Image($this->friendly_url, $this->data_dir, $this->data_path, $identifier);
			}

			$script = ImageNameScript::fromIdentifier($identifier);

			return [$script, $new_path];
		}

		if ($return_image) {
			return new Image($this->friendly_url, $this->data_dir, $this->data_path, $this->noimage_identifier);
		}

		return [$script, $file];
	}

	public function setFriendlyUrl(bool $friendly_url = true): void
	{
		$this->friendly_url = $friendly_url;
	}

	/**
	 * Generate srcset attribute string for multiple image sizes.
	 *
	 * @param string $identifier Base image identifier (path)
	 * @param array<string> $sizes Array of sizes in format 'WIDTHxHEIGHT' or just 'WIDTH' (e.g., ['400x300', '800', '1200x900'])
	 * @param string $pathPrefix Path prefix (usually $basePath or $baseUrl)
	 * @param string|null $flag Resize flag (fit, fill, exact, etc.)
	 * @param int|null $quality Quality override
	 * @param bool $convertToWebp Convert JPG/PNG to WEBP format (default: true)
	 * @return string Srcset attribute value (e.g., "img-400x300.webp 400w, img-800x600.webp 800w")
	 */
	public function createSrcSet(string $identifier, array $sizes, string $pathPrefix, ?string $flag = null, ?int $quality = null, bool $convertToWebp = true): string
	{
		if (empty($sizes)) {
			return '';
		}

		$flag = $flag ?? $this->default_transform;
		$srcsetParts = [];

		// Normalize sizes (calculate height for width-only values)
		$normalizedSizes = $this->normalizeSrcsetSizes($identifier, $sizes);

		foreach ($normalizedSizes as $size) {
			$image = $this->fromIdentifier([$identifier, $size, $flag, $quality, $convertToWebp]);
			$width = (int) explode('x', $size)[0];
			$srcsetParts[] = $pathPrefix . '/' . $image->createLink() . ' ' . $width . 'w';
		}

		return implode(', ', $srcsetParts);
	}

	/**
	 * Normalize a single size - calculate height for width-only value based on original image aspect ratio.
	 *
	 * @param string $identifier Image identifier (path)
	 * @param string $size Size in format 'WIDTHxHEIGHT' or just 'WIDTH'
	 * @return string Normalized size with both width and height (e.g., '800x600')
	 */
	private function normalizeSize(string $identifier, string $size): string
	{
		// If size contains 'x', it's already in WIDTHxHEIGHT format
		if (strpos($size, 'x') !== false) {
			return $size;
		}

		// Size is width-only, need to calculate height
		$width = (int) $size;
		$originalPath = implode('/', [$this->orig_path, $identifier]);

		// Get aspect ratio from original image
		if (file_exists($originalPath)) {
			$imageInfo = @getimagesize($originalPath);
			if ($imageInfo && $imageInfo[0] > 0 && $imageInfo[1] > 0) {
				$aspectRatio = $imageInfo[0] / $imageInfo[1]; // width / height
			} else {
				$aspectRatio = 1; // Fallback to square if can't read
			}
		} else {
			$aspectRatio = 1; // Fallback to square if file doesn't exist
		}

		// Calculate height maintaining aspect ratio
		$height = (int) round($width / $aspectRatio);
		return $width . 'x' . $height;
	}

	/**
	 * Normalize srcset sizes - calculate height for width-only values based on original image aspect ratio.
	 *
	 * @param string $identifier Image identifier (path)
	 * @param array<string> $sizes Array of sizes (e.g., ['400x300', '800', '1200'])
	 * @return array<string> Normalized sizes with both width and height (e.g., ['400x300', '800x600', '1200x900'])
	 */
	private function normalizeSrcsetSizes(string $identifier, array $sizes): array
	{
		$normalized = [];
		foreach ($sizes as $size) {
			$normalized[] = $this->normalizeSize($identifier, $size);
		}

		return $normalized;
	}

	/**
	 * Generate HTML image attributes (src and optionally srcset) for Latte templates.
	 *
	 * @param mixed $args Arguments from Latte template
	 * @param string $pathPrefix Path prefix (usually $basePath or $baseUrl)
	 * @return string HTML attributes string (e.g., ' src="..." srcset="..."')
	 */
	public function createImageAttributes(mixed $args, string $pathPrefix): string
	{
		if (!is_array($args)) {
			$args = [$args];
		}

		// Unwrap if arguments are wrapped in array (Latte parser behavior)
		if (count($args) === 1 && is_array($args[0]) && isset($args[0]['path'])) {
			$args = $args[0];
		}

		// Positional arguments: new format
		// Args: [path, srcset|size, flag, quality, convertToWebp]
		$identifier = $args[0] ?? null;
		$sizeOrSrcset = $args[1] ?? null;
		$flag = $args[2] ?? null;
		$quality = $args[3] ?? null;
		$convertToWebp = $args[4] ?? true; // Default: convert to WEBP

		// Determine if $args[1] is srcset (array) or single size (string)
		if (is_array($sizeOrSrcset)) {
			$srcsetSizes = $sizeOrSrcset;
			$size = null;
		} else {
			$srcsetSizes = null;
			$size = $sizeOrSrcset;
		}

		// If no identifier, return empty
		if (!$identifier) {
			return ' src=""';
		}

		$output = '';

		// Generate main src attribute
		if (is_array($srcsetSizes) && !empty($srcsetSizes)) {
			// Use the largest size for src (last in array)
			$mainSize = $size ?? end($srcsetSizes);
			// Normalize size (calculate height if only width is provided)
			$mainSize = $this->normalizeSize($identifier, $mainSize);
			$mainImage = $this->fromIdentifier([$identifier, $mainSize, $flag, $quality, $convertToWebp]);
			$output .= ' src="' . $pathPrefix . '/' . $mainImage->createLink() . '"';

			// Generate srcset
			$srcset = $this->createSrcSet($identifier, $srcsetSizes, $pathPrefix, $flag, $quality, $convertToWebp);
			if ($srcset) {
				$output .= ' srcset="' . $srcset . '"';
			}
		} elseif ($size) {
			// Single size - only src
			$image = $this->fromIdentifier([$identifier, $size, $flag, $quality, $convertToWebp]);
			$output .= ' src="' . $pathPrefix . '/' . $image->createLink() . '"';
		} else {
			// No size - original image
			$image = $this->fromIdentifier($identifier);
			$output .= ' src="' . $pathPrefix . '/' . $image->createLink() . '"';
		}

		return $output;
	}

	private static function fixName(string $name): string
	{
		return Strings::webalize($name, '._');
	}

	/**
	 * Get quality setting for the given image format.
	 *
	 * Returns the configured quality for the format:
	 * - JPEG: 0-100 (default 85)
	 * - PNG: 0-9 compression level (default 6)
	 * - WEBP: 0-100 (default 80)
	 * - AVIF: 0-100 (default 30)
	 * - GIF: null (not applicable)
	 */
	private function getQualityForFormat(string $extension): ?int
	{
		$extension = strtolower($extension);

		// Map jpg to jpeg for config lookup
		if ($extension === 'jpg') {
			$extension = 'jpeg';
		}

		// Check if format exists in config (including null values like gif)
		if (array_key_exists($extension, $this->quality)) {
			return $this->quality[$extension];
		}

		// Fall back to jpeg quality for unknown formats
		return $this->quality['jpeg'] ?? 85;
	}

	/**
	 * @return string[]
	 * @throws ImageExtensionException
	 */
	private function getSavePath(string $name, string $namespace, string $checksum): array
	{
		$prefix = substr($checksum, 0, 2);
		$dir = implode('/', [$this->orig_path, $namespace, $prefix]);

		@mkdir($dir, $this->mask, true); // Directory may exist

		preg_match('/(.*)(\.[^\.]*)/', $name, $matches);

		if (!$matches[2]) {
			throw new ImageExtensionException(sprintf('Error defining image extension (%s)', $name));
		}

		$name = $matches[1];
		$extension = $matches[2];

		while (file_exists($path = $dir . '/' . $name . $extension)) {
			$name = (!isset($i) && ($i = 2)) ? $name . '.' . $i : substr($name, 0, -(2 + (int) floor(log($i - 1, 10)))) . '.' . $i;
			$i++;
		}

		$identifier = implode('/', [$namespace, $prefix, $name . $extension]);

		return [$path, $identifier];
	}

}
