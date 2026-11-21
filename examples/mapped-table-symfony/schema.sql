CREATE TABLE workshops (
    id TEXT PRIMARY KEY,
    title TEXT NOT NULL,
    capacity INTEGER NOT NULL CHECK (capacity > 0)
);

CREATE TABLE reservations (
    workshop_id TEXT NOT NULL REFERENCES workshops (id),
    reference TEXT NOT NULL,
    seats INTEGER NOT NULL CHECK (seats > 0),
    PRIMARY KEY (workshop_id, reference)
);
