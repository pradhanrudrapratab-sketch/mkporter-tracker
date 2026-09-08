CREATE TABLE IF NOT EXISTS ride_waypoints (
    id         BIGSERIAL PRIMARY KEY,
    ride_id    BIGINT NOT NULL REFERENCES rides(id) ON DELETE CASCADE,
    sequence   INT NOT NULL,
    landmark   TEXT,
    latitude   DOUBLE PRECISION,
    longitude  DOUBLE PRECISION,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_waypoints_ride ON ride_waypoints(ride_id, sequence);
