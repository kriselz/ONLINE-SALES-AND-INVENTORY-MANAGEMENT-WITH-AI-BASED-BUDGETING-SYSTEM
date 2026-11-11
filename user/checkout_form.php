<?php
include 'config.php';
session_start();

// TEST LOGIN 
if (!isset($_SESSION['user_id'])) { // Removed hardcoded user_id for production
    header('Location: login.php'); // Redirect to login if not logged in
    exit();
}

if (!isset($_GET['product_id'])) {
    header("Location: product_screen.php");
    exit();
}

$product_id = (int) $_GET['product_id'];
$user_id = (int) $_SESSION['user_id'];

// Fetch Product Details
$result = $conn->query("SELECT * FROM products WHERE id=$product_id");

if ($result && $result->num_rows > 0) {
    $product = $result->fetch_assoc();
} else {
    $product = [
        'product_name' => 'Product not found',
        'price' => 0
    ];
}

$user_result = $conn->query("SELECT * FROM users WHERE id=$user_id");
$user_data = $user_result ? $user_result->fetch_assoc() : [];
$default_address = trim((string)($user_data['address'] ?? ''));
$display_name = trim((string)($user_data['full_name'] ?? '')) ?: trim((string)($user_data['username'] ?? ''));
$saved_addresses = [];
$saved_addresses_result = $conn->query("SELECT id, address, latitude, longitude, is_default FROM addresses WHERE user_id=$user_id ORDER BY is_default DESC, id DESC");
if ($saved_addresses_result) {
    while ($saved_address = $saved_addresses_result->fetch_assoc()) {
        $saved_addresses[] = $saved_address;
    }
}
$selected_saved_address_id = null;
foreach ($saved_addresses as $saved_address) {
    if ((int)($saved_address['is_default'] ?? 0) === 1) {
        $selected_saved_address_id = (int)$saved_address['id'];
        $default_address = (string)$saved_address['address'];
        break;
    }
}
if ($selected_saved_address_id === null && $default_address === '' && $saved_addresses) {
    $selected_saved_address_id = (int)$saved_addresses[0]['id'];
    $default_address = (string)$saved_addresses[0]['address'];
}
$default_destination_point = null;
foreach ($saved_addresses as $saved_address) {
    if ((int)$saved_address['id'] === $selected_saved_address_id
        && is_numeric($saved_address['latitude'] ?? null)
        && is_numeric($saved_address['longitude'] ?? null)) {
        $default_destination_point = [(float)$saved_address['latitude'], (float)$saved_address['longitude']];
        break;
    }
}
$map_destination_point = $default_destination_point ?? [14.6382, 120.9839];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout | Customer</title>
    <style>
        /* RESET BASE STYLES */
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #fdfdfd; }

        /* MAIN CONTENT LAYOUT (Eksaktong sukat mo) */
        .main-content { 
            margin-left: 260px; 
            padding: 20px; 
            width: calc(100% - 260px); 
            min-height: 100vh; 
        }

        /* STYLING PARA SA CHECKOUT FORM */
        .checkout-container {
            background: white;
            border: 1px solid #eee;
            border-radius: 15px;
            padding: 25px;
            max-width: 700px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }
        .product-preview {
            border-bottom: 2px dashed #eee;
            margin-bottom: 20px;
            padding-bottom: 15px;
        }
        .product-preview h3 { color: #4a1018; margin-bottom: 5px; }
        .price-text { font-size: 1.2rem; font-weight: bold; color: #e31e24; }
        
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; font-weight: 600; margin-bottom: 5px; font-size: 14px; }
        .form-group input, .form-group textarea {
            width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 10px; font-size: 14px;
        }
        .btn-order {
            background: #e31e24; color: white; border: none; padding: 15px; width: 100%;
            border-radius: 10px; font-weight: bold; cursor: pointer; transition: 0.3s;
        }
        .btn-order:hover { background: #4a1018; }

        .map-search {
            display: flex;
            gap: 10px;
            margin-bottom: 12px;
        }
        .map-search input {
            flex: 1;
        }
        .map-search button {
            background: #4a1018;
            color: white;
            border: none;
            border-radius: 10px;
            padding: 12px 18px;
            cursor: pointer;
            font-weight: 600;
        }
        .map-shell {
            position: relative;
            margin-bottom: 12px;
        }
        #map {
            width: 100%;
            height: 300px;
            border-radius: 18px;
            border: 1px solid #dfe6eb;
            overflow: hidden;
            background: #edf2f4;
            box-shadow: inset 0 0 0 1px rgba(119, 142, 160, 0.12);
            filter: saturate(0.9) contrast(1.06);
        }
        .route-overlay {
            position: absolute;
            right: 18px;
            bottom: 18px;
            background: rgba(255, 255, 255, 0.92);
            border: 1px solid rgba(75, 64, 90, 0.12);
            border-radius: 12px;
            box-shadow: 0 8px 24px rgba(30, 43, 63, 0.14);
            padding: 10px 12px;
            min-width: 120px;
            z-index: 500;
        }
        .route-label {
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #8191a6;
            margin-bottom: 4px;
        }
        .route-meta {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 10px;
        }
        .route-meta strong {
            font-size: 14px;
            color: #1b2433;
        }
        .route-meta span {
            font-size: 11px;
            color: #4f6480;
            font-weight: 600;
        }
        .custom-map-pin {
            background: transparent;
            border: none;
        }
        .map-pin {
            width: 26px;
            height: 26px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            font-size: 11px;
            font-weight: 800;
            color: white;
            border: 3px solid rgba(255,255,255,0.75);
            box-shadow: 0 4px 10px rgba(0,0,0,0.18);
        }
        .map-pin.pickup {
            background: linear-gradient(135deg, #ff5d73, #d6154a);
        }
        .map-pin.drop {
            background: linear-gradient(135deg, #8d5cf6, #5b2ec5);
        }
        .map-note {
            font-size: 12px;
            color: #666;
            margin-top: 6px;
            margin-bottom: 10px;
        }
    </style>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" />
</head>
<body>

    <?php include 'sidebar_user.php'; ?>

    <div class="main-content">
        <h2 style="margin-bottom: 20px; color: #333;">Checkout</h2>

        <div class="checkout-container">
            <div class="product-preview">
                <h3><?php echo htmlspecialchars($product['product_name']); ?></h3>
                <p class="price-text">₱<?php echo number_format($product['price'], 2); ?></p>
            </div>

            <form action="buy_now_process.php" method="POST">
                <input type="hidden" name="product_id" value="<?php echo $product_id; ?>">
                <input type="hidden" name="latitude" id="latitude">
                <input type="hidden" name="longitude" id="longitude">

                <div class="form-group">
                    <label>Full Name</label>
                    <input type="text" name="fullname" value="<?php echo htmlspecialchars($display_name); ?>" required>
                </div>

                <div class="form-group">
                    <label>Saved Delivery Address</label>
                    <select id="savedAddress" aria-label="Choose a saved delivery address">
                        <option value="">Choose an address or search a new one</option>
                        <?php if ($default_address !== '' && $selected_saved_address_id === null): ?>
                            <option value="<?php echo htmlspecialchars($default_address); ?>" selected>Account address: <?php echo htmlspecialchars($default_address); ?></option>
                        <?php endif; ?>
                        <?php foreach ($saved_addresses as $saved): ?>
                            <option value="<?php echo htmlspecialchars($saved['address']); ?>"
                                data-latitude="<?php echo htmlspecialchars((string)($saved['latitude'] ?? '')); ?>"
                                data-longitude="<?php echo htmlspecialchars((string)($saved['longitude'] ?? '')); ?>"
                                <?php echo (int)$saved['id'] === $selected_saved_address_id ? 'selected' : ''; ?>>
                                <?php echo (int)($saved['is_default'] ?? 0) === 1 ? 'Default address: ' : 'Saved address: '; ?><?php echo htmlspecialchars($saved['address']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Find your delivery location</label>
                    <div class="map-search">
                        <input id="addressSearch" type="text" placeholder="Search address or landmark...">
                        <button type="button" id="searchMapBtn">Search</button>
                    </div>
                    <div class="map-shell">
                        <div id="map"></div>
                        <div class="route-overlay" aria-live="polite">
                            <div class="route-label">Drop-off</div>
                            <div class="route-meta">
                                <strong>3 min</strong>
                                <span>1.1 km</span>
                            </div>
                        </div>
                    </div>
                    <p class="map-note">Search an address or click the map to set your delivery location.</p>
                </div>

                <div class="form-group">
                    <label>Shipping Address</label>
                    <textarea id="address" name="address" rows="3" placeholder="Street Name, Barangay, City" required><?php echo htmlspecialchars($default_address); ?></textarea>
                </div>

                <div class="form-group">
                    <label>Quantity</label>
                    <input type="number" name="quantity" value="1" min="1">
                </div>

                <button type="submit" class="btn-order">Confirm Order</button>
            </form>
        </div>
    </div>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    <script>
        const storePoint = [14.6391328, 120.9833358];
        const defaultDestinationPoint = <?= json_encode($map_destination_point) ?>;
        const map = L.map('map', { zoomControl: false }).setView(defaultDestinationPoint, 15);
        L.control.zoom({ position: 'bottomright' }).addTo(map);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap contributors'
        }).addTo(map);

        function createMapPin(type) {
            return L.divIcon({
                className: 'custom-map-pin',
                html: '<span class="map-pin ' + type + '">' + (type === 'pickup' ? 'P' : 'D') + '</span>',
                iconSize: [26, 26],
                iconAnchor: [13, 26]
            });
        }

        const routePoints = [
            [14.6008, 120.9822],
            [14.6031, 120.9844],
            [14.6056, 120.9878],
            [14.6078, 120.9908]
        ];

        L.polyline(routePoints, {
            color: '#ffffff',
            weight: 18,
            opacity: 1,
            lineCap: 'round',
            lineJoin: 'round'
        }).addTo(map);

        L.polyline(routePoints, {
            color: '#5d5ae6',
            weight: 10,
            opacity: 1,
            lineCap: 'round',
            lineJoin: 'round'
        }).addTo(map);

        L.polyline(routePoints, {
            color: '#7a6df8',
            weight: 4,
            opacity: 0.9,
            lineCap: 'round',
            lineJoin: 'round'
        }).addTo(map);

        L.marker(storePoint, { icon: createMapPin('pickup') }).addTo(map);
        const marker = L.marker(defaultDestinationPoint, { icon: createMapPin('drop') }).addTo(map);
        map.setView(defaultDestinationPoint, 15);

        const addressField = document.getElementById('address');
        const savedAddress = document.getElementById('savedAddress');
        const searchInput = document.getElementById('addressSearch');
        const latitudeField = document.getElementById('latitude');
        const longitudeField = document.getElementById('longitude');
        const savedDefaultCoordinates = <?= json_encode($default_destination_point) ?>;
        if (Array.isArray(savedDefaultCoordinates)) {
            latitudeField.value = savedDefaultCoordinates[0];
            longitudeField.value = savedDefaultCoordinates[1];
        }

        savedAddress.addEventListener('change', function () {
            if (this.value) {
                addressField.value = this.value;
                const selectedOption = this.options[this.selectedIndex];
                const latitude = Number(selectedOption.dataset.latitude);
                const longitude = Number(selectedOption.dataset.longitude);
                if (Number.isFinite(latitude) && Number.isFinite(longitude)
                    && selectedOption.dataset.latitude !== '' && selectedOption.dataset.longitude !== '') {
                    marker.setLatLng([latitude, longitude]);
                    latitudeField.value = latitude;
                    longitudeField.value = longitude;
                    map.setView([latitude, longitude], 15);
                } else {
                    latitudeField.value = '';
                    longitudeField.value = '';
                }
            } else {
                addressField.value = '';
                latitudeField.value = '';
                longitudeField.value = '';
            }
        });

        function updateAddressFromCoordinates(lat, lng) {
            latitudeField.value = lat;
            longitudeField.value = lng;

            fetch(`https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=${lat}&lon=${lng}`)
                .then(response => response.json())
                .then(data => {
                    const formatted = data.display_name || 'Selected location';
                    addressField.value = formatted;
                })
                .catch(() => {
                    addressField.value = `Selected location (${lat}, ${lng})`;
                });
        }

        map.on('click', function (event) {
            const { lat, lng } = event.latlng;
            marker.setLatLng([lat, lng]);
            updateAddressFromCoordinates(lat, lng);
        });

        document.getElementById('searchMapBtn').addEventListener('click', function () {
            const query = searchInput.value.trim();
            if (!query) {
                alert('Please enter an address or landmark to search.');
                return;
            }

            fetch(`https://nominatim.openstreetmap.org/search?format=jsonv2&q=${encodeURIComponent(query)}`)
                .then(response => response.json())
                .then(results => {
                    if (!results || results.length === 0) {
                        alert('No location found. Try a different address.');
                        return;
                    }

                    const place = results[0];
                    const lat = parseFloat(place.lat);
                    const lng = parseFloat(place.lon);

                    map.setView([lat, lng], 16);
                    marker.setLatLng([lat, lng]);
                    updateAddressFromCoordinates(lat, lng);
                })
                .catch(() => {
                    alert('The map search is unavailable at the moment. Please enter the address manually.');
                });
        });

        searchInput.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                document.getElementById('searchMapBtn').click();
            }
        });
    </script>

</body>
</html>