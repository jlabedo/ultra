# Mapper

Mapper has a role similar to the normalization part of Symfony Serializer: it transforms PHP objects into PHP arrays and reconstructs objects from those representations. Its APIs also support scalar values where appropriate. It does not provide JSON or XML encoding and decoding, and business rules belong to the application. Object-to-object conversion remains an open evolution rather than a current promised capability.

Compiled normalizers are a central example of Ultra's preference for moving work to compilation. See the [compilation decision](../adr/0002-prefer-compiled-runtime-code.md).

UltraNormalizer can generate a missing normalizer on first use when Mapper_Compiler is available, then cache and load the generated PHP class. This supports standalone use. Precompiling normalizers is the recommended production approach so generation does not occur on the first runtime call.


## Custom normalizers

Register RuntimeNormalizerInterface implementations during configuration, before normalization or code generation. Registration uses exact handled types. Already generated normalizers take precedence in the in-memory cache, and registered runtime types influence generated code for nested values.

Denormalization checks representation structure, including required properties and scalar types; it is not a business-rule validation engine.

See [UltraNormalizer](../../src/Mapper/UltraNormalizer.php).
