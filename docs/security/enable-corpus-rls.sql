-- Enable Row-Level Security on every public table of the history-corpus Supabase project
-- (ophofmkxmehmeojvsijc).  Run this once in the Supabase SQL editor (or via a privileged
-- connection) to close the `rls_disabled_in_public` finding.
--
-- WHY: Supabase auto-exposes every `public`-schema table through PostgREST, reachable with the
--      project URL + the public anon key. With RLS disabled, anyone with those can read, edit,
--      and DELETE the corpus. The data itself is public-domain (Wikidata/Wikipedia derived), so
--      this is an integrity/availability risk (tampering), not a confidentiality leak — but it
--      should still be closed.
--
-- SAFE FOR THE APP: the Laravel app connects as the `postgres` role
--      (CORPUS_DB_USERNAME=postgres.ophofmkxmehmeojvsijc), which has BYPASSRLS — its reads are
--      unaffected. We deliberately add NO policies, so the anon/authenticated PostgREST roles get
--      zero access: the corpus is reachable only through the privileged server-side connection.
--
-- REVERSIBLE: ALTER TABLE public.<table> DISABLE ROW LEVEL SECURITY;

ALTER TABLE public.article_entities ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.boundaries       ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.entities         ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.fact_entities    ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.figures          ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.gazetteer_places ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.historical_maps  ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.history_articles ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.history_facts    ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.polities         ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.scrape_log       ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.texts            ENABLE ROW LEVEL SECURITY;

-- spatial_ref_sys is a PostGIS system table (coordinate-system reference data, not your corpus).
-- Supabase flags it too; enabling RLS is harmless but may error if the extension owns it — that
-- error is safe to ignore.
ALTER TABLE public.spatial_ref_sys  ENABLE ROW LEVEL SECURITY;
