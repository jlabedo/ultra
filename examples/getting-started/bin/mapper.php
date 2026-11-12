<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Ultra\Mapper\GeneratedNormalizerName;
use Ultra\Mapper\UltraNormalizer;
use Ultra\Mapper_Compiler\NormalizerGenerator;

final readonly class Profile
{
    public function __construct(
        public string $name,
        public int $visits,
    ) {}
}

$directory = dirname(__DIR__) . '/var/profile-normalizers';
$namespace = 'GettingStartedProfileGenerated';
if (!is_dir($directory)) {
    mkdir($directory, 0o777, true);
}
$class = GeneratedNormalizerName::forType(Profile::class, $namespace);
$file = $directory . '/' . GeneratedNormalizerName::cacheFileName($class);
file_put_contents($file, NormalizerGenerator::for(Profile::class, $class, [])->generate());

$normalizer = new UltraNormalizer($directory, $namespace);
$profile = new Profile('Ada', 3);
$data = $normalizer->toScalar($profile);
if (['name' => 'Ada', 'visits' => 3] !== $data || $normalizer->fromScalar($data, Profile::class) != $profile) {
    throw new RuntimeException('Profile round trip failed.');
}
echo json_encode($data, JSON_THROW_ON_ERROR) . "\n";
