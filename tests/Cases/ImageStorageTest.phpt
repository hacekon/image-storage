<?php declare(strict_types = 1);

namespace Tests\Cases;

use Contributte\ImageStorage\ImageStorage;
use Contributte\Tester\Toolkit;
use Nette\Http\FileUpload;
use Nette\Utils\Image;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tester\Assert;

require_once __DIR__ . '/../bootstrap.php';

function createStorage(): ImageStorage
{
	return new ImageStorage(
		__DIR__ . '/__files__',
		'data',
		__DIR__ . '/__files__',
		'sha1_file',
		'sha1',
		['jpeg' => 2, 'png' => 2, 'webp' => 2, 'avif' => 2, 'gif' => null],
		'fit',
		'n/aa/s.jpg',
		false
	);
}

function cleanupImages(): void
{
	$path = __DIR__ . '/__files__/images';

	if (!file_exists($path)) {
		return;
	}

	$iterator = new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS);
	$files = new RecursiveIteratorIterator($iterator, RecursiveIteratorIterator::CHILD_FIRST);

	foreach ($files as $file) {
		if ($file->isDir()) {
			rmdir($file->getRealPath());
		} else {
			unlink($file->getRealPath());
		}
	}
}

Toolkit::test(static function (): void {
	$storage = createStorage();
	$files = __DIR__ . '/__files__/files';
	@mkdir($files . '/49', 0777, true);

	$file_array = [
		sprintf('%s/49/kitty.100x100.fit.q85.jpg', $files),
		sprintf('%s/49/kitty.100x200.fit.q85.jpg', $files),
		sprintf('%s/49/kitty.100x200.exact.q85.jpg', $files),
		sprintf('%s/49/kitty.100x200.shrink_only.q85.jpg', $files),
		sprintf('%s/49/kitty.100x200.fill.q1.jpg', $files),
		sprintf('%s/49/kitty.100x200.stretch.q85.jpg', $files),
		sprintf('%s/49/kitty.100x200.fill.q10.jpg', $files),
		sprintf('%s/49/kitty.200x200crop100x150x100x100.fit.q85.jpg', $files),
		sprintf('%s/49/kitty.100x200.fill.q100.jpg', $files),
		sprintf('%s/49/kitty.20x20.fit.q85.jpg', $files),
		sprintf('%s/49/kitty.100x200.fill.q85.jpg', $files),
		sprintf('%s/49/kitty.jpg', $files),
	];

	foreach ($file_array as $name) {
		touch($name);
	}

	$storage->delete('files/49/kitty.jpg');

	foreach ($file_array as $name) {
		Assert::falsey(file_exists($name));
	}

	foreach ($file_array as $name) {
		touch($name);
	}

	$storage->delete('files/49/kitty.jpg', true);

	$originalImage = array_pop($file_array);
	foreach ($file_array as $name) {
		Assert::falsey(file_exists($name));
	}

	Assert::truthy(file_exists($originalImage));

	cleanupImages();
});

Toolkit::test(static function (): void {
	$storage = createStorage();
	$files = __DIR__ . '/__files__/files';
	$tempImagePath = $files . '/tmp.jpg';

	$imageContent = Image::fromBlank(1, 1)->toString();
	$result = file_put_contents($tempImagePath, $imageContent);

	if ($result === false) {
		throw new \Exception('Unable to save temporary test image!');
	}

	$upload = new FileUpload([
		'name' => 'upload.jpg',
		'type' => 'image/jpg',
		'size' => 20,
		'tmp_name' => $tempImagePath,
		'error' => 0,
	]);

	$storage->saveUpload($upload, 'images');

	$prefix = substr(sha1($imageContent), 0, 2);

	$savedImage = sprintf(
		'%s/../images/%s/upload.jpg',
		$files,
		$prefix
	);
	Assert::truthy(file_exists($savedImage));

	cleanupImages();
});

Toolkit::test(static function (): void {
	$storage = createStorage();
	$imageFileName = 'content.jpg';
	$files = __DIR__ . '/__files__/files';

	$imageContent = Image::fromBlank(1, 1)->toString();

	$prefix = substr(sha1($imageContent), 0, 2);

	$storage->saveContent($imageContent, $imageFileName, 'images');
	$savedImage = sprintf(
		'%s/../images/%s/content.jpg',
		$files,
		$prefix
	);
	Assert::truthy(file_exists($savedImage));

	$storage->saveContent($imageContent, $imageFileName, 'images');
	$savedImageCopy = sprintf(
		'%s/../images/%s/content.2.jpg',
		$files,
		$prefix
	);
	Assert::truthy(file_exists($savedImageCopy));

	cleanupImages();
});

Toolkit::test(static function (): void {
	$storage = createStorage();

	// Test associative array with named parameters
	$image = $storage->fromIdentifier([
		'path' => 'files/49/kitty.jpg',
		'size' => '100x100',
		'flag' => 'fit',
		'quality' => 85,
	]);

	Assert::type(\Contributte\ImageStorage\Image::class, $image);
	Assert::contains('kitty.100x100.fit.q85.jpg', $image->createLink());

	cleanupImages();
});

Toolkit::test(static function (): void {
	$storage = createStorage();

	// Test associative array with minimal parameters (only path)
	$image = $storage->fromIdentifier([
		'path' => 'files/49/kitty.jpg',
	]);

	Assert::type(\Contributte\ImageStorage\Image::class, $image);
	Assert::equal('data/files/49/kitty.jpg', $image->createLink());

	cleanupImages();
});

Toolkit::test(static function (): void {
	$storage = createStorage();

	// Test backward compatibility with positional array
	$image = $storage->fromIdentifier([
		'files/49/kitty.jpg',
		'100x100',
		'fill',
		90,
	]);

	Assert::type(\Contributte\ImageStorage\Image::class, $image);
	Assert::contains('kitty.100x100.fill.q90.jpg', $image->createLink());

	cleanupImages();
});

// createImageAttributes: width/height with srcset (default addDimensions=true)
Toolkit::test(static function (): void {
	$storage = createStorage();

	$testImagePath = __DIR__ . '/__files__/images/ab/test.jpg';
	@mkdir(dirname($testImagePath), 0777, true);
	Image::fromBlank(800, 600)->save($testImagePath);

	$output = $storage->createImageAttributes(
		['images/ab/test.jpg', ['400x300', '800x600']],
		'/base'
	);

	Assert::contains('width="800"', $output);
	Assert::contains('height="600"', $output);

	cleanupImages();
});

// createImageAttributes: no width/height when addDimensions=false via args[5]
Toolkit::test(static function (): void {
	$storage = createStorage();

	$testImagePath = __DIR__ . '/__files__/images/ab/test.jpg';
	@mkdir(dirname($testImagePath), 0777, true);
	Image::fromBlank(800, 600)->save($testImagePath);

	$output = $storage->createImageAttributes(
		['images/ab/test.jpg', ['400x300', '800x600'], null, null, true, false],
		'/base'
	);

	Assert::notContains('width=', $output);
	Assert::notContains('height=', $output);

	cleanupImages();
});

// createImageAttributes: no width/height when addDimensions=false via method param
Toolkit::test(static function (): void {
	$storage = createStorage();

	$testImagePath = __DIR__ . '/__files__/images/ab/test.jpg';
	@mkdir(dirname($testImagePath), 0777, true);
	Image::fromBlank(800, 600)->save($testImagePath);

	$output = $storage->createImageAttributes(
		['images/ab/test.jpg', ['400x300', '800x600']],
		'/base',
		false
	);

	Assert::notContains('width=', $output);
	Assert::notContains('height=', $output);

	cleanupImages();
});

// createImageAttributes: width/height with single size
Toolkit::test(static function (): void {
	$storage = createStorage();

	$testImagePath = __DIR__ . '/__files__/images/ab/test.jpg';
	@mkdir(dirname($testImagePath), 0777, true);
	Image::fromBlank(800, 600)->save($testImagePath);

	$output = $storage->createImageAttributes(
		['images/ab/test.jpg', '640x480'],
		'/base'
	);

	Assert::contains('width="640"', $output);
	Assert::contains('height="480"', $output);

	cleanupImages();
});

// createImageAttributes: maximum variant is used for dimensions (not just last in array)
Toolkit::test(static function (): void {
	$storage = createStorage();

	$testImagePath = __DIR__ . '/__files__/images/ab/test.jpg';
	@mkdir(dirname($testImagePath), 0777, true);
	Image::fromBlank(1200, 900)->save($testImagePath);

	// Sizes in non-ascending order - max width is 1200
	$output = $storage->createImageAttributes(
		['images/ab/test.jpg', ['1200x900', '400x300', '800x600']],
		'/base'
	);

	Assert::contains('width="1200"', $output);
	Assert::contains('height="900"', $output);

	cleanupImages();
});
