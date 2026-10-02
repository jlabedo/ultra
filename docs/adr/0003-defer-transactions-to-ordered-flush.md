# Defer transactions to the ordered flush

Ultra executes application reads and decisions before opening the transaction for the unit-of-work flush. This keeps transactions short and allows buffered work to be ordered together, reducing deadlock risks while checking optimistic expectations at write time. Ordering does not eliminate every deadlock; the design aims to provide tools for handling the remaining limits rather than placing the entire application operation inside a long transaction.
