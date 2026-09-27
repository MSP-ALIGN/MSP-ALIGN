-- 1.24: the app is now called MSP-ALIGN. A branding name still set to the old default
-- is cleared so the new default name shows; a custom name is left alone.
UPDATE settings SET value = '' WHERE name = 'brand_name' AND value = 'Mountaineer Align';
