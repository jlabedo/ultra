# Mapper

Mapper has a role similar to the normalization part of Symfony Serializer: it transforms PHP objects into PHP arrays and reconstructs objects from those representations. Its APIs also support scalar values where appropriate. It does not provide JSON or XML encoding and decoding, and business rules belong to the application. Object-to-object conversion remains an open evolution rather than a current promised capability.

Compiled normalizers are a central example of Ultra's preference for moving work to compilation. See the [compilation decision](../adr/0002-prefer-compiled-runtime-code.md).

UltraNormalizer can generate a missing normalizer on first use when Mapper_Compiler is available, then cache and load the generated PHP class. This supports standalone use. Precompiling normalizers is the recommended production approach so generation does not occur on the first runtime call.


## Custom normalizers

Register RuntimeNormalizerInterface implementations during configuration, before normalization or code generation. Registration uses exact handled types. Already generated normalizers take precedence in the in-memory cache, and registered runtime types influence generated code for nested values.

Denormalization checks representation structure, including required properties and scalar types; it is not a business-rule validation engine.

See [UltraNormalizer](../../src/Mapper/UltraNormalizer.php).

## Test ownership and maintenance

The context's runtime, compiler and Symfony guarantees are tested together in `src/Mapper/Tests`.

| Guarantee | Suite | Cases |
| --- | --- | --- |
| Representations preserve values in both directions | [RepresentationTest](../../src/Mapper/Tests/RepresentationTest.php) | Scalars, nested objects and value wrappers, keyed collections, unions, nulls, dates, enums, constructor and stored properties, virtual properties. |
| Invalid data reports its origin | [DenormalizationErrorsTest](../../src/Mapper/Tests/DenormalizationErrorsTest.php) | Required properties, scalar types, collection indices, nested paths, unions, custom normalizers and caller context; path construction with runtime variables. |
| Traversal rejects cycles and resets after each root call | [NormalizationSafetyTest](../../src/Mapper/Tests/NormalizationSafetyTest.php) | A circular graph, recovery after failure and repeated calls. |
| Normalizer resolution respects configuration and the generated cache | [NormalizerResolutionTest](../../src/Mapper/Tests/NormalizerResolutionTest.php) | Exact registered types at root and in nested generated code, subclasses and late registration. |
| Generated files are usable runtime artifacts | [CompilationLifecycleTest](../../src/Mapper/Tests/CompilationLifecycleTest.php) | Standalone default cache, first-use generation, instance reuse, loading precompiled files without regeneration and generated class names. |
| Shapes describe usable representations for storage compilation | [NormalizationShapeTest](../../src/Mapper/Tests/NormalizationShapeTest.php) | Stored fields, value wrappers, enums, property shapes, precise runtime return types and closed array shapes; rejection of ambiguous shapes. |
| Symfony configuration produces working compiled normalizers | [ContainerCompilationTest](../../src/Mapper/Tests/ContainerCompilationTest.php) | Resource tags, AsValue autoconfiguration, runtime type tags, class parameters, multiple types and invalid configuration. |

Add cases to the suite owning their guarantee. Representation cases run once through UltraNormalizer;
the compilation and container suites use a few focused scenarios rather than replaying every conversion.
Tests that generate normalizers use separate processes and temporary directories, so a previously loaded
class or cache file cannot make another test pass. The shared setup manages those directories and contains no assertions.

Before deleting a case, identify the remaining scenario protecting its guarantee and compare covered source
lines and detection of the same mutations on unchanged production code. Analyze survivors before adding
tests; permissions, duplicate generation or equivalent changes do not automatically justify another case.
See [development verification](../development.md#tests) for commands.
