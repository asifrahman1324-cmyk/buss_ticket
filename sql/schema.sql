-- ============================================================
-- ICT BD Bus Services Ltd. - Bus Ticketing Management System
-- PostgreSQL 18 Schema
-- Database: bus_ticketing_db
-- Run this script in pgAdmin 4 (Query Tool) against bus_ticketing_db
-- ============================================================

-- Drop tables in dependency order (safe to re-run during development)
DROP TABLE IF EXISTS payment CASCADE;
DROP TABLE IF EXISTS ticket CASCADE;
DROP TABLE IF EXISTS booking CASCADE;
DROP TABLE IF EXISTS schedule CASCADE;
DROP TABLE IF EXISTS route CASCADE;
DROP TABLE IF EXISTS bus CASCADE;
DROP TABLE IF EXISTS employee_details CASCADE;
DROP TABLE IF EXISTS users CASCADE;

-- ------------------------------------------------------------
-- USERS  (Administrator / Ticket Counter Staff / Customer)
-- ------------------------------------------------------------
CREATE TABLE users (
    user_id        SERIAL PRIMARY KEY,
    full_name      VARCHAR(150) NOT NULL,
    email          VARCHAR(150) NOT NULL UNIQUE,
    phone          VARCHAR(20),
    password_hash  VARCHAR(255) NOT NULL,
    role           VARCHAR(20) NOT NULL DEFAULT 'customer'
                   CHECK (role IN ('admin', 'staff', 'customer')),
    is_active      BOOLEAN NOT NULL DEFAULT TRUE,
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ------------------------------------------------------------
-- EMPLOYEE DETAILS (optional extension for admin / staff users)
-- ------------------------------------------------------------
CREATE TABLE employee_details (
    employee_id   SERIAL PRIMARY KEY,
    user_id       INTEGER NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
    designation   VARCHAR(100),
    joining_date  DATE,
    salary        NUMERIC(10,2),
    address       TEXT,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ------------------------------------------------------------
-- BUS
-- ------------------------------------------------------------
CREATE TABLE bus (
    bus_id        SERIAL PRIMARY KEY,
    bus_number    VARCHAR(50) NOT NULL UNIQUE,
    bus_name      VARCHAR(100),
    bus_type      VARCHAR(10) NOT NULL DEFAULT 'Non-AC'
                  CHECK (bus_type IN ('AC', 'Non-AC')),
    total_seats   INTEGER NOT NULL CHECK (total_seats > 0),
    image_path    VARCHAR(255),
    status        VARCHAR(20) NOT NULL DEFAULT 'active'
                  CHECK (status IN ('active', 'inactive', 'maintenance')),
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ------------------------------------------------------------
-- ROUTE
-- ------------------------------------------------------------
CREATE TABLE route (
    route_id            SERIAL PRIMARY KEY,
    origin              VARCHAR(100) NOT NULL,
    destination         VARCHAR(100) NOT NULL,
    distance_km         NUMERIC(6,2),
    estimated_duration  VARCHAR(50),
    base_fare           NUMERIC(10,2) NOT NULL CHECK (base_fare >= 0),
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ------------------------------------------------------------
-- SCHEDULE  (a bus running a route on a given date/time)
-- ------------------------------------------------------------
CREATE TABLE schedule (
    schedule_id      SERIAL PRIMARY KEY,
    bus_id           INTEGER NOT NULL REFERENCES bus(bus_id) ON DELETE CASCADE,
    route_id         INTEGER NOT NULL REFERENCES route(route_id) ON DELETE CASCADE,
    departure_date   DATE NOT NULL,
    departure_time   TIME NOT NULL,
    arrival_time     TIME,
    fare             NUMERIC(10,2) NOT NULL CHECK (fare >= 0),
    available_seats  INTEGER NOT NULL CHECK (available_seats >= 0),
    created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ------------------------------------------------------------
-- BOOKING
-- ------------------------------------------------------------
CREATE TABLE booking (
    booking_id      SERIAL PRIMARY KEY,
    customer_id     INTEGER NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
    schedule_id     INTEGER NOT NULL REFERENCES schedule(schedule_id) ON DELETE CASCADE,
    seat_numbers    VARCHAR(150) NOT NULL,
    seat_count      INTEGER NOT NULL CHECK (seat_count > 0),
    total_amount    NUMERIC(10,2) NOT NULL CHECK (total_amount >= 0),
    booking_status  VARCHAR(20) NOT NULL DEFAULT 'confirmed'
                    CHECK (booking_status IN ('confirmed', 'cancelled', 'completed')),
    booking_date    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ------------------------------------------------------------
-- TICKET  (one row per seat / passenger within a booking)
-- ------------------------------------------------------------
CREATE TABLE ticket (
    ticket_id        SERIAL PRIMARY KEY,
    booking_id       INTEGER NOT NULL REFERENCES booking(booking_id) ON DELETE CASCADE,
    ticket_code      VARCHAR(50) NOT NULL UNIQUE,
    passenger_name   VARCHAR(150) NOT NULL,
    seat_number      VARCHAR(10) NOT NULL,
    issued_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ------------------------------------------------------------
-- PAYMENT
-- ------------------------------------------------------------
CREATE TABLE payment (
    payment_id       SERIAL PRIMARY KEY,
    booking_id       INTEGER NOT NULL REFERENCES booking(booking_id) ON DELETE CASCADE,
    amount           NUMERIC(10,2) NOT NULL CHECK (amount >= 0),
    method           VARCHAR(20) NOT NULL DEFAULT 'cash'
                     CHECK (method IN ('cash', 'card', 'mobile_banking', 'bank_transfer')),
    status           VARCHAR(20) NOT NULL DEFAULT 'pending'
                     CHECK (status IN ('pending', 'paid', 'refunded', 'failed')),
    transaction_ref  VARCHAR(100),
    paid_at          TIMESTAMP
);

-- ------------------------------------------------------------
-- INDEXES
-- ------------------------------------------------------------
CREATE INDEX idx_schedule_bus       ON schedule(bus_id);
CREATE INDEX idx_schedule_route     ON schedule(route_id);
CREATE INDEX idx_schedule_date      ON schedule(departure_date);
CREATE INDEX idx_booking_customer   ON booking(customer_id);
CREATE INDEX idx_booking_schedule   ON booking(schedule_id);
CREATE INDEX idx_booking_date       ON booking(booking_date);
CREATE INDEX idx_ticket_booking     ON ticket(booking_id);
CREATE INDEX idx_payment_booking    ON payment(booking_id);
CREATE INDEX idx_employee_user      ON employee_details(user_id);

-- ------------------------------------------------------------
-- NOTE ON ACCOUNTS
-- ------------------------------------------------------------
-- This script intentionally does NOT insert any user rows.
-- Register your first account through register.php — the very
-- first account created on an empty users table is automatically
-- promoted to 'admin' (see register.php). Every account after
-- that defaults to 'customer'.
--
-- To promote any existing customer account to staff or admin later,
-- run (in pgAdmin 4 Query Tool):
--   UPDATE users SET role = 'staff' WHERE email = 'staff@example.com';