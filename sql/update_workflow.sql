-- Update events table to add registration_ended status
ALTER TABLE events 
MODIFY COLUMN status ENUM('draft','registration','registration_ended','ongoing','completed') DEFAULT 'draft';

-- Update any events that have registration end date in the past but still have registration status
UPDATE events 
SET status = 'registration_ended' 
WHERE status = 'registration' 
  AND reg_end_date < CURDATE();
