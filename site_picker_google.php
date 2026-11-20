<?php
/** Path: /site_picker_google.php */
error_reporting(E_ALL);
ini_set('display_errors', 1);
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'db.php';
require_once 'engine/menu_generator.php';
require_once __DIR__ . '/engine/site_status_lib.php';

if (empty($_SESSION['user_id'])) {
    header('Location: /login_kiosk.php');
    exit;
}

$user_lvl = (int)($_SESSION['user_level'] ?? $_SESSION['user_role'] ?? 99);
$org_id   = (int)($_SESSION['org_id'] ?? 0);
$is_super = ($user_lvl <= 10);

if (!$is_super && $org_id <= 0) {
    http_response_code(403);
    exit('No organization is assigned to this session.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_coords'])) {
    $sid = (int)($_POST['sid'] ?? 0);
    $lat = $_POST['lat'] ?? '';
    $lng = $_POST['lng'] ?? '';
    $street   = trim((string)($_POST['street_address'] ?? ''));
    $city     = trim((string)($_POST['city'] ?? ''));
    $province = trim((string)($_POST['province'] ?? ''));
    $zip      = trim((string)($_POST['zipcode'] ?? ''));
    $country  = trim((string)($_POST['country'] ?? ''));
    $has_addr = ($street !== '' || $city !== '' || $province !== '' || $zip !== '' || $country !== '');
    if ($sid > 0 && $lat !== '' && $lng !== '') {
        if ($has_addr) {
            $sql = "UPDATE sites SET target_lat = :lat, target_lng = :lng, street_address = :street, city = :city, province = :province, zipcode = :zip, country = :country WHERE SId = :sid";
            $params = [
                ':lat' => $lat, ':lng' => $lng, ':sid' => $sid,
                ':street' => $street, ':city' => $city, ':province' => $province,
                ':zip' => $zip, ':country' => $country,
            ];
        } else {
            $sql = "UPDATE sites SET target_lat = :lat, target_lng = :lng WHERE SId = :sid";
            $params = [':lat' => $lat, ':lng' => $lng, ':sid' => $sid];
        }
        if (!$is_super) {
            $sql .= " AND org_id = :org";
            $params[':org'] = $org_id;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        if ($stmt->rowCount() > 0) {
            $message = "<div style='background:#d4edda; padding:15px; border-radius:8px;'>✅ Site Updated!</div>";
        }
    }
}

$active_sql = sip_sites_active_sql('');
$site_cols = 'SId, nick_name, street_address, city, province, zipcode, country, target_lat, target_lng';
if ($is_super) {
    $sites = $pdo->query(
        "SELECT $site_cols FROM sites WHERE nick_name IS NOT NULL AND $active_sql ORDER BY nick_name ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
} else {
    $st = $pdo->prepare(
        "SELECT $site_cols FROM sites WHERE org_id = ? AND nick_name IS NOT NULL AND $active_sql ORDER BY nick_name ASC"
    );
    $st->execute([$org_id]);
    $sites = $st->fetchAll(PDO::FETCH_ASSOC);
}

function sip_picker_search_address(array $site): string {
    $parts = [];
    foreach (['street_address', 'city', 'province', 'zipcode', 'country'] as $k) {
        $v = trim((string)($site[$k] ?? ''));
        if ($v !== '') $parts[] = $v;
    }
    return implode(', ', $parts);
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Google Site Picker</title>
    <script src="https://maps.googleapis.com/maps/api/js?key=&libraries=places"></script>
    <style>
        body { font-family: sans-serif; padding: 20px; padding-top: 100px; background: #f8f9fa; }
        #map { height: 600px; width: 100%; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.2); }
        .panel { background: white; padding: 20px; border-radius: 12px; margin-bottom: 15px; display: flex; gap: 10px; align-items: center; box-shadow: 0 2px 6px rgba(0,0,0,0.1); }
        input, select { padding: 12px; border: 1px solid #ccc; border-radius: 8px; }
        #pac-input { flex-grow: 1; border: 2px solid #4285F4; }
        button { padding: 12px 24px; background: #34A853; color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: bold; }
    </style>
</head>
<body>
<?php if (function_exists('generateMenu')) echo generateMenu($pdo); ?>

<div class="panel">
    <select id="sid_selector">
        <option value="">-- Choose Site --</option>
        <?php foreach ($sites as $site):
            $search_addr = sip_picker_search_address($site);
        ?>
            <option value="<?= (int)$site['SId'] ?>"
                    data-lat="<?= htmlspecialchars((string)($site['target_lat'] ?? ''), ENT_QUOTES) ?>"
                    data-lng="<?= htmlspecialchars((string)($site['target_lng'] ?? ''), ENT_QUOTES) ?>"
                    data-address="<?= htmlspecialchars($search_addr, ENT_QUOTES) ?>"
                    data-nick="<?= htmlspecialchars((string)($site['nick_name'] ?? ''), ENT_QUOTES) ?>"
                    data-street="<?= htmlspecialchars((string)($site['street_address'] ?? ''), ENT_QUOTES) ?>"
                    data-city="<?= htmlspecialchars((string)($site['city'] ?? ''), ENT_QUOTES) ?>"
                    data-province="<?= htmlspecialchars((string)($site['province'] ?? ''), ENT_QUOTES) ?>"
                    data-zip="<?= htmlspecialchars((string)($site['zipcode'] ?? ''), ENT_QUOTES) ?>"
                    data-country="<?= htmlspecialchars((string)($site['country'] ?? ''), ENT_QUOTES) ?>">
                <?= htmlspecialchars($site['nick_name']) ?>
            </option>
        <?php endforeach; ?>
    </select>
    <input id="pac-input" type="text" placeholder="Search Address...">
    <form method="POST" style="display:flex; gap:10px;">
        <input type="hidden" name="sid" id="form_sid">
        <input type="hidden" name="street_address" id="f_street">
        <input type="hidden" name="city" id="f_city">
        <input type="hidden" name="province" id="f_province">
        <input type="hidden" name="zipcode" id="f_zip">
        <input type="hidden" name="country" id="f_country">
        <input type="text" name="lat" id="lat" placeholder="Lat" readonly style="width:110px;">
        <input type="text" name="lng" id="lng" placeholder="Lng" readonly style="width:110px;">
        <button type="submit" name="save_coords">SAVE LOCATION</button>
    </form>
</div>

<?php if (isset($message)) echo $message; ?>
<div id="map"></div>

<script>
    let map, marker, searchBox, geocoder;

    function readPos(pos) {
        if (!pos) return null;
        let lat = pos.lat, lng = pos.lng;
        if (typeof lat === 'function') lat = lat.call(pos);
        if (typeof lng === 'function') lng = lng.call(pos);
        lat = Number(lat); lng = Number(lng);
        if (!Number.isFinite(lat) || !Number.isFinite(lng)) return null;
        return { lat: lat, lng: lng };
    }

    function markerPos() {
        if (!marker) return null;
        if (typeof marker.getPosition === 'function') {
            const p = readPos(marker.getPosition());
            if (p) return p;
        }
        return readPos(marker.position);
    }

    function eventPos(e) {
        if (!e) return null;
        if (e.latLng) return readPos(e.latLng);
        return readPos(e.latLng || e.position || null);
    }

    function setAddrFields(parts) {
        parts = parts || {};
        const set = function(id, v) {
            const el = document.getElementById(id);
            if (el) el.value = v || '';
        };
        set('f_street', parts.street || '');
        set('f_city', parts.city || '');
        set('f_province', parts.province || '');
        set('f_zip', parts.zip || '');
        set('f_country', parts.country || '');
    }

    function parseAddressComponents(components) {
        const list = components || [];
        const get = function(type, shortName) {
            for (let i = 0; i < list.length; i++) {
                const c = list[i];
                if (c.types && c.types.indexOf(type) !== -1) {
                    return shortName ? (c.short_name || c.long_name || '') : (c.long_name || '');
                }
            }
            return '';
        };
        const street = [get('street_number'), get('route')].filter(Boolean).join(' ').trim();
        const city = get('locality') || get('sublocality') || get('postal_town') || get('administrative_area_level_3');
        return {
            street: street,
            city: city,
            province: get('administrative_area_level_1', true),
            zip: get('postal_code'),
            country: get('country', true)
        };
    }

    function syncForm(lat, lng) {
        document.getElementById('lat').value = Number(lat).toFixed(8);
        document.getElementById('lng').value = Number(lng).toFixed(8);
        document.getElementById('form_sid').value = document.getElementById('sid_selector').value;
    }

    function placeMarker(lat, lng) {
        const pos = { lat: Number(lat), lng: Number(lng) };
        marker.setPosition(pos);
        marker.setVisible(true);
        marker.setDraggable(true);
        if (typeof marker.setMap === 'function' && typeof marker.getMap === 'function' && !marker.getMap()) {
            marker.setMap(map);
        }
        return pos;
    }

    function reverseGeocode(lat, lng) {
        if (!geocoder) return;
        geocoder.geocode({ location: { lat: lat, lng: lng } }, function(results, status) {
            if (status !== 'OK' || !results || !results[0]) return;
            const best = results[0];
            const input = document.getElementById('pac-input');
            if (input) input.value = best.formatted_address || input.value;
            const parts = parseAddressComponents(best.address_components || []);
            if (!parts.street && best.formatted_address) {
                parts.street = String(best.formatted_address).split(',')[0].trim();
            }
            setAddrFields(parts);
        });
    }

    function update(lat, lng, opts) {
        opts = opts || {};
        lat = Number(lat);
        lng = Number(lng);
        if (!Number.isFinite(lat) || !Number.isFinite(lng)) return;
        placeMarker(lat, lng);
        syncForm(lat, lng);
        if (opts.address) {
            document.getElementById('pac-input').value = opts.address;
        }
        if (opts.components) {
            setAddrFields(opts.components);
        } else if (opts.reverse) {
            reverseGeocode(lat, lng);
        }
    }

    function onMarkerMoved(doReverse) {
        const fromMarker = markerPos();
        if (!fromMarker) return;
        syncForm(fromMarker.lat, fromMarker.lng);
        if (doReverse) reverseGeocode(fromMarker.lat, fromMarker.lng);
    }

    function initMap() {
        map = new google.maps.Map(document.getElementById("map"), {
            center: { lat: 51.0447, lng: -114.0719 },
            zoom: 16,
            mapTypeId: 'hybrid'
        });
        geocoder = new google.maps.Geocoder();
        marker = new google.maps.Marker({
            map: map,
            draggable: true,
            optimized: false,
            visible: false
        });
        marker.setDraggable(true);
        marker.addListener("drag", function() { onMarkerMoved(false); });
        marker.addListener("dragend", function(e) {
            const fromEvent = eventPos(e);
            if (fromEvent) {
                syncForm(fromEvent.lat, fromEvent.lng);
                placeMarker(fromEvent.lat, fromEvent.lng);
                reverseGeocode(fromEvent.lat, fromEvent.lng);
                return;
            }
            onMarkerMoved(true);
        });
        const input = document.getElementById("pac-input");
        try {
            searchBox = new google.maps.places.SearchBox(input);
            searchBox.addListener("places_changed", function() {
                const places = searchBox.getPlaces() || [];
                if (!places.length) return;
                const place = places[0];
                if (!place.geometry || !place.geometry.location) return;
                const loc = place.geometry.location;
                map.setCenter(loc);
                map.setZoom(19);
                update(loc.lat(), loc.lng(), {
                    address: place.formatted_address || input.value,
                    components: parseAddressComponents(place.address_components || [])
                });
            });
        } catch (err) {
            console.warn('SearchBox unavailable', err);
        }
        map.addListener("click", function(e) {
            const p = eventPos(e);
            if (!p) return;
            update(p.lat, p.lng, { reverse: true });
        });
    }

    document.getElementById('sid_selector').onchange = function() {
        const opt = this.options[this.selectedIndex];
        const input = document.getElementById('pac-input');
        if (!this.value) {
            input.value = '';
            document.getElementById('form_sid').value = '';
            document.getElementById('lat').value = '';
            document.getElementById('lng').value = '';
            setAddrFields({});
            return;
        }
        const addr = (opt.dataset.address || '').trim();
        const nick = (opt.dataset.nick || '').trim();
        input.value = addr || nick || '';
        document.getElementById('form_sid').value = this.value;
        setAddrFields({
            street: opt.dataset.street || '',
            city: opt.dataset.city || '',
            province: opt.dataset.province || '',
            zip: opt.dataset.zip || '',
            country: opt.dataset.country || ''
        });
        const lat = parseFloat(opt.dataset.lat);
        const lng = parseFloat(opt.dataset.lng);
        if (Number.isFinite(lat) && Number.isFinite(lng) && !(lat === 0 && lng === 0)) {
            map.setCenter({lat: lat, lng: lng});
            map.setZoom(18);
            update(lat, lng);
        }
    };
    window.onload = initMap;
</script>
</body>
</html>
