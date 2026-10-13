# Mapper

For a first round trip, precompilation and Symfony resource setup, follow [getting started](getting-started.md).

Mapper has a role similar to the normalization part of Symfony Serializer: it transforms PHP objects into PHP arrays and reconstructs objects from those representations. Its APIs also support scalar values where appropriate. It does not provide JSON or XML encoding and decoding, and business rules belong to the application. Object-to-object conversion remains an open evolution rather than a current promised capability.

Compiled normalizers are a central example of Ultra's preference for moving work to compilation. See the [compilation decision](../adr/0002-prefer-compiled-runtime-code.md).

UltraNormalizer can generate a missing normalizer on first use when Mapper_Compiler is available, then cache and load the generated PHP class. This supports standalone use. Precompiling normalizers is the recommended production approach so generation does not occur on the first runtime call.

`UltraNormalizer`, `MapperCompilerPass` and `MapperExtension` accept a `generationNamespace` argument,
defaulting to `Ultra\Normalizer\Generated`. Use a distinct namespace for each compilation or runtime
configuration that can coexist in a PHP process. The namespace and handled type identify both the generated
class and its file, so separate compilations can share an artifact directory. Keep the namespace when
moving precompiled artifacts to another directory; their identity does not depend on the directory path.
A class already loaded for that namespace remains reusable, and an instance keeps its generated normalizers
even when their files are removed. Register runtime normalizers before generating or loading the configuration.
Generated class names escape underscores as `__` and namespace separators as `_s`, so types such as
`Acme\A_B` and `Acme_A\B` remain distinct within the same generation namespace. Literal escape-like
names such as `Acme\A_sB` also remain distinct from `Acme\A\B`. Precompiled artifacts must use
the naming scheme of the runtime version loading them.


## Dates

Properties declared as `DateTime`, `DateTimeImmutable` or `DateTimeInterface` normalize to RFC 3339 strings
with six fractional digits and a numeric UTC offset, such as `2026-05-25T12:34:56.123456+02:00`.
Denormalization preserves the instant and microseconds; `DateTimeInterface` properties hydrate as
`DateTimeImmutable`. Nullable date properties preserve null. The representation preserves the offset,
but does not store a named timezone's identity or daylight-saving rules.

## Custom normalizers

Register RuntimeNormalizerInterface implementations during configuration, before normalization or code generation. Registration uses exact handled types. Already generated normalizers take precedence in the in-memory cache, and registered runtime types influence generated code for nested values.

Denormalization checks representation structure, including required properties and scalar types; it is not a business-rule validation engine.

Normalization rejects references to objects still in the active traversal. An object can be shared
by several properties and normalized at each occurrence once its previous normalization has completed.
Each call leaves the active traversal when it returns or throws, so a failed normalization does not
affect subsequent calls.

See [UltraNormalizer](../../src/Mapper/Runtime/UltraNormalizer.php).

## Test ownership and maintenance

The context's runtime, compiler and Symfony guarantees are tested together in `src/Mapper/Tests`.

| Guarantee | Suite | Cases |
| --- | --- | --- |
| Representations preserve values in both directions | [RepresentationTest](../../src/Mapper/Tests/RepresentationTest.php) | Scalars, nested objects and value wrappers, keyed collections, unions, nulls, dates, enums, constructor and stored properties, virtual properties. |
| Invalid data reports its origin | [DenormalizationErrorsTest](../../src/Mapper/Tests/DenormalizationErrorsTest.php) | Required properties, scalar types, collection indices, nested paths, unions, custom normalizers and caller context; path construction with runtime variables. |
| Traversal rejects cycles and accepts shared objects | [NormalizationSafetyTest](../../src/Mapper/Tests/NormalizationSafetyTest.php) | A circular graph, shared runtime-normalized properties, recovery after failure and repeated calls. |
| Normalizer resolution respects configuration and the generated cache | [NormalizerResolutionTest](../../src/Mapper/Tests/NormalizerResolutionTest.php) | Exact registered types at root and in nested generated code, subclasses and late registration. |
| Generated files are usable runtime artifacts | [CompilationLifecycleTest](../../src/Mapper/Tests/CompilationLifecycleTest.php) | Standalone default cache, first-use generation, instance reuse, loading precompiled files without regeneration and generated class names. |
| Compilation identities preserve independent configurations and types | [GenerationIdentityTest](../../src/Mapper/Tests/GenerationIdentityTest.php) | Different runtime configurations in one process and artifact directory; types with colliding flattened names or literal escape-like names through first-use generation and precompilation; moved precompiled artifacts retain their namespace. |
| Shapes describe usable representations for storage compilation | [NormalizationShapeTest](../../src/Mapper/Tests/NormalizationShapeTest.php) | Stored fields, value wrappers, enums, property shapes, precise runtime return types and closed array shapes; rejection of ambiguous shapes. |
| Symfony configuration produces working compiled normalizers | [ContainerCompilationTest](../../src/Mapper/Tests/ContainerCompilationTest.php) | Resource tags, AsValue autoconfiguration, runtime type tags, class parameters, multiple types and invalid configuration. |

Add cases to the suite owning their guarantee. Representation cases run once through UltraNormalizer;
the compilation and container suites use a few focused scenarios rather than replaying every conversion.
Tests that generate normalizers use a distinct generation namespace and temporary directory per case,
so a previously loaded class or cache file cannot make another test pass. Only the standalone default-cache
case requires a fresh process to exercise the default namespace before a class is loaded. The shared setup
manages the namespaces and directories and contains no assertions.

See [development verification](../development.md#tests) for commands and the
[refactoring checks](../development.md#refactoring-tests) before deleting or adding cases.
