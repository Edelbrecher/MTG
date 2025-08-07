-- Add proxy column to collections table
ALTER TABLE collections ADD COLUMN proxy BOOLEAN DEFAULT FALSE AFTER foil;

-- Add index for proxy searches
CREATE INDEX idx_proxy ON collections(proxy);
CREATE INDEX idx_foil_proxy ON collections(foil, proxy);
