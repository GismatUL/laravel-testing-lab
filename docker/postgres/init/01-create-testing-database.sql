-- Runs ONLY when the postgres data volume is first initialised.
-- Integration tests use a separate database so RefreshDatabase never wipes
-- your seeded 100k-row dataset. PostGIS is enabled by the migrations.
CREATE DATABASE laravel_testing OWNER laravel;
