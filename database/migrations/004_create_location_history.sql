CREATE TABLE IF NOT EXISTS location_history (
    id          BIGSERIAL PRIMARY KEY,
    ride_id     BIGINT NOT NULL REFERENCES rides(id) ON DELETE CASCADE,
    sequence    INT NOT NULL,
    latitude    DOUBLE PRECISION NOT NULL,
    longitude   DOUBLE PRECISION NOT NULL,
    recorded_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_location_ride_seq ON location_history(ride_id, sequence);
