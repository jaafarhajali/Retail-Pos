-- 012_return_whish.sql (2026-11-05, Aya): a refund can be sent back by Whish, like a debt can be paid by Whish.
-- A Whish refund is USD only, exact to the cent (no 5,000 LBP rounding), leaves the shop's Whish account and never
-- the drawer: no cash movement, so the drawer count stays right.
ALTER TABLE return_refunds MODIFY method ENUM('cash','debt_reduction','whish') NOT NULL;
