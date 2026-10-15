# Normalize your first value

Install the [getting-started application](../../examples/getting-started/README.md#setup). This recipe needs no PostgreSQL connection or table setup. Run it from that application's directory:

```bash
php bin/mapper.php
```

Expected output:

```json
{"name":"Ada","visits":3}
```

## Compile and round-trip

The complete [mapper script](../../examples/getting-started/bin/mapper.php) defines a readonly `Profile`, chooses an artifact directory and generation namespace, and uses `GeneratedNormalizerName` to obtain the class and file identities. It passes the profile class, generated class name and an empty runtime-type map to `NormalizerGenerator::for()`, then writes the generated PHP file.

It creates `UltraNormalizer` with that same directory and namespace. `toScalar()` loads the compiled artifact and produces the representation; `fromScalar()` rebuilds the profile. The script checks both values before printing JSON. JSON encoding is a separate application step.

For a small standalone application, constructing `UltraNormalizer` and calling it without precompilation can generate missing artifacts on first use when Mapper_Compiler is available. For production, move the script's generation step into the build, make model classes autoloadable, and deploy the generated files with the matching code. The runtime directory and namespace must address those artifacts. Register custom runtime normalizers before compilation or loading; pass their handled types in the generator's runtime-type map. See the [Mapper contract](README.md) for configuration identities and custom-type resolution.

## Symfony

Enable `MapperExtension` in the kernel as shown in [Symfony assembly](../symfony.md). Mark values to compile with `#[Ultra\Mapper\Attribute\AsValue]`, discover them as autoconfigured resources with autowiring disabled, and inject `UltraNormalizer` into application services. For example, adapt the starter's `Contact` service registration to a `Profile` class carrying `#[AsValue]`.

Run `php bin/console cache:warmup` during the application build. `MapperExtension` compiles discovered values into the kernel build directory's `mapper` subdirectory. A class merely present on disk is not a discovered resource. StateStore's `#[State]` discovery already tags its state classes for normalization, so the starter's contact requires no additional `#[AsValue]`.

Continue with [dates, validation and custom normalizers](README.md#dates). Representation validation checks shape and types; put business-rule validation in the application.
