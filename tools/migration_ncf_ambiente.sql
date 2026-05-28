-- Migration: Add ambiente column to ncf_sequences
-- Run each statement one at a time in phpMyAdmin

-- Step 1: Add ambiente column (existing rows default to 'certecf')
ALTER TABLE ncf_sequences
    ADD COLUMN ambiente VARCHAR(20) NOT NULL DEFAULT 'certecf';

-- Step 2: Drop old unique index on type (run whichever name matches your DB)
ALTER TABLE ncf_sequences DROP INDEX type;

-- Step 3: Add composite unique key (type + ambiente)
ALTER TABLE ncf_sequences ADD UNIQUE KEY uq_type_ambiente (type, ambiente);

-- Step 4: Insert ecf rows starting at 0 for all e-CF types
INSERT IGNORE INTO ncf_sequences (type, prefix, current_value, description, ambiente)
SELECT type, prefix, 0, description, 'ecf'
FROM ncf_sequences
WHERE type LIKE 'E%' AND ambiente = 'certecf';
