-- Migration: Add ambiente column to ncf_sequences
-- Run once per environment (local + server)
-- Safe to run multiple times: each step is idempotent

-- Step 1: Add ambiente column (existing rows default to 'certecf')
ALTER TABLE ncf_sequences
    ADD COLUMN IF NOT EXISTS ambiente VARCHAR(20) NOT NULL DEFAULT 'certecf';

-- Step 2: Drop existing unique key on type (if any) and add composite unique
ALTER TABLE ncf_sequences DROP INDEX IF EXISTS type;
ALTER TABLE ncf_sequences DROP INDEX IF EXISTS uq_type;
ALTER TABLE ncf_sequences DROP INDEX IF EXISTS unique_type;
ALTER TABLE ncf_sequences ADD UNIQUE KEY IF NOT EXISTS uq_type_ambiente (type, ambiente);

-- Step 3: Insert ecf rows (production) starting at 0 for all e-CF types
INSERT IGNORE INTO ncf_sequences (type, prefix, current_value, description, ambiente)
SELECT type, prefix, 0, description, 'ecf'
FROM ncf_sequences
WHERE type LIKE 'E%' AND ambiente = 'certecf';
