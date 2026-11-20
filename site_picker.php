<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'db.php'; // Using your PDO connection
require_once 'engine/menu_generator.php';
require_once __DIR__ . '/engine/site_status_lib.php';

if (empty($_SESSION['user_id'])) {
    header('Location: /login_kiosk.php');
    exit;
}
$user_lvl = (int)($_SESSION['user_level'] ?? $_SESSION['user_role'] ?? 99);
$org_id   = (int)($_SESSION['org_id'] ?? 0);
$is_super = ($user_lvl <= 10);

// SAVE LOGIC
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_coords'])) {
    $sid = (int)($_POST['sid'] ?? 0);
    if ($sid > 0) {
        if ($is_super) {
            $stmt = $pdo->prepare("UPDATE sites SET target_lat = :lat, target_lng = :lng WHERE SId = :sid");
            $stmt->execute([':lat' => $_POST['lat'], ':lng' => $_POST['lng'], ':sid' => $sid]);
        } else {
            $stmt = $pdo->prepare("UPDATE sites SET target_lat = :lat, target_lng = :lng WHERE SId = :sid AND org_id = :org");
            $stmt->execute([':lat' => $_POST['lat'], ':lng' => $_POST['lng'], ':sid' => $sid, ':org' => $org_id]);
        }
    }
    $message = "✅ Location Saved!";
}

// FETCH SITES — superadmin: all orgs; everyone else: own org only
$active_sql = sip_sites_active_sql('');
if ($is_super) {
    $sites = $pdo->query("SELECT SId, nick_name, target_lat, target_lng FROM sites WHERE $active_sql ORDER BY nick_name ASC")->fetchAll(PDO::FETCH_ASSOC);
} else {
    $st = $pdo->prepare("SELECT SId, nick_name, target_lat, target_lng FROM sites WHERE org_id = ? AND $active_sql ORDER BY nick_name ASC");
    $st->execute([$org_id]);
    $sites = $st->fetchAll(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>3D Site Picker</title>
    <link href="https://api.mapbox.com/mapbox-gl-js/v2.15.0/mapbox-gl.css" rel="stylesheet">
    <script src="https://api.mapbox.com/mapbox-gl-js/v2.15.0/mapbox-gl.js"></script>
    <script src="https://api.mapbox.com/mapbox-gl-js/plugins/mapbox-gl-geocoder/v5.0.0/mapbox-gl-geocoder.min.js"></script>
    <link rel="stylesheet" href="https://api.mapbox.com/mapbox-gl-js/plugins/mapbox-gl-geocoder/v5.0.0/mapbox-gl-geocoder.css" type="text/css">

    <style>
        body { margin: 0; padding: 20px; padding-top: 100px; font-family: sans-serif; background: #2c3e50; }
        #map { height: 70vh; width: 100%; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.5); }
        .panel { background: white; padding: 20px; border-radius: 12px; margin-bottom: 15px; display: flex; gap: 10px; align-items: center; }
        input, select { padding: 12px; border: 1px solid #ccc; border-radius: 6px; }
        button { padding: 12px 25px; background: #e67e22; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: bold; }
        .instructions { color: white; margin-bottom: 10px; font-size: 0.9em; }
    </style>
</head>
<body>
<?php if (function_exists('generateMenu')) echo generateMenu($pdo); ?>

<div class="instructions">💡 <b>3D Controls:</b> Right-click and drag to tilt/rotate. Use the search bar to find an address.</div>

<div class="panel">
    <form method="POST" style="display:flex; gap:10px; width:100%;">
        <select name="sid" id="sid" required style="flex-grow: 1;">
            <option value="">-- Select Site --</option>
            <?php foreach ($sites as $site): ?>
                <option value="<?= $site['SId'] ?>" data-lat="<?= $site['target_lat'] ?>" data-lng="<?= $site['target_lng'] ?>">
                    <?= htmlspecialchars($site['nick_name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <input type="text" name="lat" id="lat" placeholder="Latitude" readonly>
        <input type="text" name="lng" id="lng" placeholder="Longitude" readonly>
        <button type="submit" name="save_coords">SAVE 3D POINT</button>
    </form>
</div>

<div id="map"></div>

<script>
    // 🔑 REPLACE THIS WITH YOUR MAPBOX TOKEN
   
mapboxgl.accessToken = ''; // set the Mapbox token on the server; do not commit it

    const map = new mapboxgl.Map({
    container: 'map',
    // 'satellite-streets-v12' is the highest quality Mapbox offers
    style: 'mapbox://styles/mapbox/satellite-streets-v12', 
    center: [-114.0719, 51.0447],
    zoom: 12,
    pitch: 45,
    antialias: true,
    // This forces the map to use high-definition tiles for retina/4K screens
    pixelRatio: window.devicePixelRatio || 1 
});

// Once loaded, make sure the labels and buildings are sharp
map.on('style.load', () => {
    map.setConfigProperty('basemap', 'lightPreset', 'day'); // Brightens the satellite view
});

    // Add 3D Terrain
    map.on('load', () => {
        map.addSource('mapbox-dem', {
            'type': 'raster-dem',
            'url': 'mapbox://mapbox.mapbox-terrain-dem-v1',
            'tileSize': 512,
            'maxzoom': 14
        });
        map.setTerrain({ 'source': 'mapbox-dem', 'exaggeration': 1.5 });

        // Add 3D Buildings
        map.addLayer({
            'id': '3d-buildings',
            'source': 'composite',
            'source-layer': 'building',
            'filter': ['==', 'extrude', 'true'],
            'type': 'fill-extrusion',
            'minzoom': 15,
            'paint': {
                'fill-extrusion-color': '#aaa',
                'fill-extrusion-height': ['get', 'height'],
                'fill-extrusion-base': ['get', 'min_height'],
                'fill-extrusion-opacity': 0.6
            }
        });
    });

    // Add Search Bar
    const geocoder = new MapboxGeocoder({
        accessToken: mapboxgl.accessToken,
        mapboxgl: mapboxgl,
        placeholder: "Search Address or Building..."
    });
    map.addControl(geocoder);

    let marker = new mapboxgl.Marker({ color: "#e67e22", draggable: true });

    // Click map to place marker
    map.on('click', (e) => {
        updateMarker(e.lngLat.lat, e.lngLat.lng);
    });

    function updateMarker(lat, lng) {
        marker.setLngLat([lng, lat]).addTo(map);
        document.getElementById('lat').value = lat.toFixed(8);
        document.getElementById('lng').value = lng.toFixed(8);
    }

    // Dropdown Logic
    document.getElementById('sid').addEventListener('change', function() {
        const opt = this.options[this.selectedIndex];
        const lat = parseFloat(opt.dataset.lat);
        const lng = parseFloat(opt.dataset.lng);
        if (lat && lng) {
            map.flyTo({ center: [lng, lat], zoom: 18, pitch: 60 });
            updateMarker(lat, lng);
        }
    });
</script>

</body>
</html>