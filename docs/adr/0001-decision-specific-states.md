# Decision-specific states without mandatory aggregate roots

Ultra uses states tailored to decisions without requiring an aggregate root. EventSourcedState is inspired by Dynamic Consistency Boundary (DCB); StateStore explores a similar approach without event sourcing and provides an alternative to a complex ORM for simple CRUD use cases. Both approaches put optimistic concurrency control at the center of their design, allowing decisions to depend on the relevant states rather than forcing those states into a fixed aggregate boundary.
