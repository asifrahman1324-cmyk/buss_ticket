-- ============================================================
-- Sessions Table for Database-backed Sessions (Vercel Serverless)
-- Run this script in pgAdmin 4 or Supabase SQL Editor
-- ============================================================

CREATE TABLE IF NOT EXISTS sessions (
    id          VARCHAR(255) PRIMARY KEY,
    data        TEXT NOT NULL,
    timestamp   INTEGER NOT NULL
);

-- Index for session garbage collection
CREATE INDEX IF NOT EXISTS idx_sessions_timestamp ON sessions(timestamp);
