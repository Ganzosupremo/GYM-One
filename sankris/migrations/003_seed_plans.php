<?php
// Planes iniciales de Sankris (precios 2026). Sólo se cargan si hay programas y todavía no hay planes,
// así nunca pisan cambios hechos desde el panel. También se pueden cargar desde la página Planes.
return function (\Sankris\Migrator $m) {
    \Sankris\Seed::plans2026($m->db());
};
