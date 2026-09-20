ALTER TABLE order_shipping_steps
  ADD COLUMN deadline_hours INT NULL AFTER is_dispatch_step;

UPDATE order_shipping_steps os
LEFT JOIN offer_shipping_steps src ON src.id=os.source_step_id
SET os.deadline_hours=src.deadline_hours
WHERE os.deadline_hours IS NULL AND src.id IS NOT NULL;
