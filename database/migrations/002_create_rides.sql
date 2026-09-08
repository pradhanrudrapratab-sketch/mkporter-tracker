CREATE TABLE IF NOT EXISTS rides (
    id                    BIGSERIAL PRIMARY KEY,
    user_id               BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    booking_id            VARCHAR(128) NOT NULL,
    tracking_url          TEXT NOT NULL,
    status                VARCHAR(32) NOT NULL DEFAULT 'unknown',

    crn                   VARCHAR(64),
    updated_crn           VARCHAR(64),
    geo_region_id         VARCHAR(64),

    partner_name          VARCHAR(128),
    partner_mobile        VARCHAR(32),
    vehicle_type          VARCHAR(64),
    vehicle_number        VARCHAR(32),

    pickup_landmark       TEXT,
    pickup_lat            DOUBLE PRECISION,
    pickup_lng            DOUBLE PRECISION,

    drop_landmark         TEXT,
    drop_lat              DOUBLE PRECISION,
    drop_lng              DOUBLE PRECISION,

    has_waypoints         BOOLEAN NOT NULL DEFAULT FALSE,
    is_rental             BOOLEAN NOT NULL DEFAULT FALSE,
    is_helper             BOOLEAN NOT NULL DEFAULT FALSE,
    is_outstation         BOOLEAN NOT NULL DEFAULT FALSE,

    last_partner_lat      DOUBLE PRECISION,
    last_partner_lng      DOUBLE PRECISION,

    accepted_at           TIMESTAMPTZ,
    completed_at          TIMESTAMPTZ,

    last_polled_at        TIMESTAMPTZ,
    next_poll_at          TIMESTAMPTZ DEFAULT NOW(),

    poll_count            INT NOT NULL DEFAULT 0,
    successful_poll_count INT NOT NULL DEFAULT 0,
    error_count           INT NOT NULL DEFAULT 0,

    last_error            VARCHAR(64),
    last_error_at         TIMESTAMPTZ,

    report_due_at         TIMESTAMPTZ,
    report_sent_at        TIMESTAMPTZ,
    report_status         VARCHAR(32),
    report_attempts       INT NOT NULL DEFAULT 0,
    report_last_error     TEXT,

    processing_until      TIMESTAMPTZ,

    created_at            TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at            TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_rides_user_booking ON rides(user_id, booking_id)
    WHERE status NOT IN ('completed', 'error', 'deleted');

CREATE INDEX IF NOT EXISTS idx_rides_status_poll    ON rides(status, next_poll_at);
CREATE INDEX IF NOT EXISTS idx_rides_report_due     ON rides(status, report_due_at) WHERE report_status IN ('pending');
CREATE INDEX IF NOT EXISTS idx_rides_user_id        ON rides(user_id);
