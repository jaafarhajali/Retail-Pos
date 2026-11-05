-- The supplier's discount on a purchase (Aya, 2026-11-05): typed as an amount or a percent on the New purchase
-- form. total_usd stays what is really owed (subtotal - discount); the discount is spread over the lines, so
-- every item enters stock at the price really paid and the profit reports stay right.
ALTER TABLE purchases ADD COLUMN discount_usd DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER total_usd;
