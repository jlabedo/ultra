# Prefer compiled code for runtime performance

Ultra accepts greater compilation complexity to move as much work as possible out of runtime execution. PHP reflection at runtime is a design smell when the same work can be done at compilation; generated code is preferred over metadata interpreted by generic runtime code where practical. Mapper's compiled normalizer classes exemplify this choice: runtime performance is the motivation for accepting the additional compiler machinery.

Generation must still justify its complexity and readability cost. ProjectionRuntime deliberately uses handler metadata and dynamic method calls: the expected performance gain from generating that dispatch is not considered sufficient to justify a compiler for it.
