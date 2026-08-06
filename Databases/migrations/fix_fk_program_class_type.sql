-- ============================================================
-- Migration: Fix fk_program_class_type foreign key
-- 
-- Problem: The FK on musabaqa_programs.class_type_id is pointing
--          to the wrong database. It must reference kauzariyya.class_types
--          (the main dashboard DB), not kauzariyya_musabaqa.class_types
--          (which has no class_types table).
--
-- Run this script against the kauzariyya_musabaqa database.
-- ============================================================

USE kauzariyya_musabaqa;

-- Step 1: Drop the broken foreign key
ALTER TABLE musabaqa_programs
    DROP FOREIGN KEY fk_program_class_type;

-- Step 2: Recreate it pointing to the correct database (kauzariyya)
ALTER TABLE musabaqa_programs
    ADD CONSTRAINT fk_program_class_type
    FOREIGN KEY (class_type_id) REFERENCES kauzariyya.class_types (id)
    ON DELETE SET NULL
    ON UPDATE CASCADE;
