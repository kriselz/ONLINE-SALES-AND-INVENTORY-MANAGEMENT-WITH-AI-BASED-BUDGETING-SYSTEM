<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../app_config.php';

$products = [];
$catalogError = '';
$isCustomer = ($_SESSION['role'] ?? '') === 'user';
$customerLoginUrl = '../user/login.php';
$catalogUrl = $isCustomer ? '../user/product_screen.php' : $customerLoginUrl;

function getHomepageProductImage(string $image, string $productName): string
{
    $image = trim($image);
    if ($image !== '') {
        $imageParts = parse_url($image);
        if (is_array($imageParts)
            && in_array(strtolower((string)($imageParts['scheme'] ?? '')), ['http', 'https'], true)
            && filter_var($image, FILTER_VALIDATE_URL)) {
            return $image;
        }

        $filename = basename(str_replace('\\', '/', $image));
        if ($filename !== '' && $filename !== '.') {
            foreach (['uploads', 'images'] as $directory) {
                if (is_file(__DIR__ . DIRECTORY_SEPARATOR . $directory . DIRECTORY_SEPARATOR . $filename)) {
                    return $directory . '/' . rawurlencode($filename);
                }
            }
        }
    }

    $imagesDirectory = __DIR__ . DIRECTORY_SEPARATOR . 'images';
    $imageFiles = is_dir($imagesDirectory) ? scandir($imagesDirectory) : false;
    if (is_array($imageFiles)) {
        $normalizedProductName = strtolower((string)preg_replace('/[^a-z0-9]/i', '', $productName));
        $bestMatch = '';
        foreach ($imageFiles as $imageFile) {
            if (!preg_match('/\.(jpe?g|png|webp)$/i', $imageFile)) {
                continue;
            }
            $normalizedFilename = strtolower((string)preg_replace('/[^a-z0-9]/i', '', pathinfo($imageFile, PATHINFO_FILENAME)));
            if (strlen($normalizedFilename) < 5
                || !str_contains($normalizedProductName, $normalizedFilename)
                || strlen($normalizedFilename) <= strlen($bestMatch)) {
                continue;
            }
            $bestMatch = $normalizedFilename;
            $matchedFile = $imageFile;
        }
        if ($bestMatch !== '') {
            return 'images/' . rawurlencode($matchedFile);
        }
    }

    return 'images/logo.png';
}

try {
    $conn = capstoneConnectDatabase();
    $sellableStock = "CASE
        WHEN EXISTS (
            SELECT 1 FROM product_batches tracked_batch
            WHERE tracked_batch.product_id = products.id
        ) THEN LEAST(
            COALESCE(products.box, 0) * COALESCE(NULLIF(products.pieces_per_box, 0), 12)
                + COALESCE(products.pieces, 0),
            COALESCE((
                SELECT SUM(sellable_batch.current_quantity)
                FROM product_batches sellable_batch
                WHERE sellable_batch.product_id = products.id
                  AND sellable_batch.recalled = 0
                  AND sellable_batch.expiration_date >= CURDATE()
            ), 0)
        )
        ELSE COALESCE(products.box, 0) * COALESCE(NULLIF(products.pieces_per_box, 0), 12)
            + COALESCE(products.pieces, 0)
    END";
    $productQuery = $conn->query("
        SELECT products.id, products.product_name, products.category, products.image,
               COALESCE(NULLIF(products.price_piece, 0), products.price, 0) AS display_price,
               $sellableStock AS available_stock
        FROM products
        WHERE products.status = 'active'
          AND COALESCE(products.prescription_required, 0) = 0
          AND LOWER(TRIM(COALESCE(products.category, ''))) <> 'prescription medicine'
          AND ($sellableStock) > 0
        ORDER BY products.id DESC
        LIMIT 6
    ");
    if (!$productQuery) {
        throw new RuntimeException('Unable to load the homepage product selection: ' . $conn->error);
    }
    while ($product = $productQuery->fetch_assoc()) {
        $products[] = $product;
    }
} catch (Throwable $exception) {
    error_log('Pharmacy homepage product catalog failed: ' . $exception->getMessage());
    $catalogError = 'Our product selection is temporarily unavailable. Please try again shortly.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#a91632">
    <meta name="description" content="Browse available medicines and pharmacy essentials from MedCare Pharmacy.">
    <title>MedCare Pharmacy | Josefina Farmacia</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/icon?family=Material+Icons+Outlined">
    <style>
        :root {
            color-scheme: light;
            --red: #ad1734;
            --red-dark: #80132b;
            --ink: #202a35;
            --muted: #647181;
            --line: #e8edf1;
            --surface: #fff;
            --soft: #f7f9fb;
            --green: #23804b;
            font-family: "Segoe UI", Arial, sans-serif;
        }
        * { box-sizing: border-box; }
        html { scroll-behavior: smooth; scroll-padding-top: 88px; }
        body { margin: 0; background: var(--surface); color: var(--ink); }
        a { color: inherit; }
        .site-header {
            position: sticky; top: 0; z-index: 20; display: flex; min-height: 76px;
            align-items: center; justify-content: space-between; gap: 24px; padding: 10px max(5vw, calc((100vw - 1200px) / 2));
            border-bottom: 1px solid rgba(32,42,53,.07); background: rgba(255,255,255,.97);
            box-shadow: 0 3px 18px rgba(32,42,53,.06);
        }
        .brand { display: inline-flex; align-items: center; gap: 11px; text-decoration: none; }
        .brand img { width: 48px; height: 48px; object-fit: contain; }
        .brand-copy { display: grid; gap: 2px; }
        .brand-name { color: var(--red-dark); font-size: 17px; font-weight: 800; letter-spacing: -.3px; }
        .brand-caption { color: var(--muted); font-size: 10px; letter-spacing: .3px; }
        .main-nav { display: flex; align-items: center; gap: 26px; }
        .main-nav a { color: #43505d; font-size: 13px; font-weight: 650; text-decoration: none; }
        .main-nav a:hover, .main-nav a:focus-visible { color: var(--red); }
        .main-nav .nav-login { padding: 10px 17px; border-radius: 8px; background: var(--red); color: white; }
        .main-nav .nav-login:hover, .main-nav .nav-login:focus-visible { background: var(--red-dark); color: white; }
        .hero {
            position: relative; display: grid; min-height: 520px; align-items: center; overflow: hidden;
            background: #47202a url("images/background.jpg") center 48% / cover no-repeat;
            color: white;
        }
        .hero::before { position: absolute; inset: 0; background: linear-gradient(90deg, rgba(47,17,27,.83) 0%, rgba(87,23,39,.7) 48%, rgba(80,24,39,.2) 100%); content: ""; }
        .hero-content { position: relative; width: min(1200px, 90%); margin: 0 auto; padding: 76px 0 88px; }
        .hero-kicker { display: inline-flex; align-items: center; gap: 8px; margin: 0 0 17px; color: #ffe0e6; font-size: 12px; font-weight: 750; letter-spacing: 1.4px; text-transform: uppercase; }
        .hero-kicker::before { width: 24px; height: 2px; background: #ffc4cf; content: ""; }
        .hero h1 { max-width: 630px; margin: 0; font-size: clamp(42px, 6vw, 68px); font-weight: 800; letter-spacing: -2.4px; line-height: 1.04; }
        .hero-copy { max-width: 540px; margin: 21px 0 29px; color: #f7e9ec; font-size: 17px; line-height: 1.7; }
        .hero-actions { display: flex; flex-wrap: wrap; gap: 12px; }
        .button { display: inline-flex; min-height: 47px; align-items: center; justify-content: center; padding: 0 21px; border: 1px solid transparent; border-radius: 8px; font-size: 13px; font-weight: 750; text-decoration: none; transition: transform .18s ease, background .18s ease; }
        .button:hover { transform: translateY(-2px); }
        .button .material-icons-outlined { margin-left: 7px; font-size: 18px; line-height: 1; }
        .button-light { background: white; color: var(--red-dark); }
        .button-outline { border-color: rgba(255,255,255,.65); color: white; }
        .button-outline:hover { background: rgba(255,255,255,.12); }
        .trust-strip { display: grid; grid-template-columns: repeat(3, 1fr); gap: 18px; width: min(1000px, 90%); margin: -35px auto 0; position: relative; z-index: 2; }
        .trust-item { display: flex; min-height: 91px; align-items: center; gap: 13px; padding: 18px; border: 1px solid var(--line); border-radius: 12px; background: white; box-shadow: 0 9px 24px rgba(29,40,52,.07); }
        .trust-icon { display: grid; width: 42px; height: 42px; flex: 0 0 42px; place-items: center; border-radius: 11px; background: #fff0f3; color: var(--red); }
        .trust-icon .material-icons-outlined { font-size: 22px; line-height: 1; }
        .trust-item strong { display: block; margin-bottom: 4px; font-size: 13px; }
        .trust-item span:last-child { color: var(--muted); font-size: 11px; line-height: 1.4; }
        .section { width: min(1200px, 90%); margin: 0 auto; padding: 78px 0; }
        .section-heading { display: flex; align-items: end; justify-content: space-between; gap: 24px; margin-bottom: 26px; }
        .eyebrow { margin: 0 0 8px; color: var(--red); font-size: 11px; font-weight: 800; letter-spacing: 1.25px; text-transform: uppercase; }
        .section-heading h2, .services-intro h2 { margin: 0; color: var(--ink); font-size: clamp(26px, 3vw, 35px); letter-spacing: -1px; }
        .section-heading p { max-width: 460px; margin: 9px 0 0; color: var(--muted); font-size: 13px; line-height: 1.6; }
        .text-link { color: var(--red); font-size: 13px; font-weight: 750; text-decoration: none; white-space: nowrap; }
        .text-link:hover { text-decoration: underline; }
        .product-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 19px; }
        .product-card { overflow: hidden; border: 1px solid var(--line); border-radius: 12px; background: white; transition: transform .2s ease, box-shadow .2s ease; }
        .product-card:hover { transform: translateY(-4px); box-shadow: 0 14px 30px rgba(29,40,52,.1); }
        .product-image { display: grid; height: 190px; place-items: center; overflow: hidden; background: #f5f7f9; }
        .product-image img { width: 100%; height: 100%; object-fit: contain; padding: 17px; }
        .product-info { padding: 17px 18px 19px; }
        .product-category { margin: 0 0 7px; color: var(--muted); font-size: 10px; font-weight: 700; letter-spacing: .7px; text-transform: uppercase; }
        .product-info h3 { min-height: 42px; margin: 0; font-size: 15px; line-height: 1.4; }
        .product-meta { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-top: 14px; }
        .product-price { color: var(--red-dark); font-size: 17px; font-weight: 800; }
        .stock-status { display: inline-flex; align-items: center; gap: 4px; color: var(--green); font-size: 11px; font-weight: 700; }
        .stock-status .material-icons-outlined { font-size: 16px; }
        .product-open { display: flex; align-items: center; justify-content: space-between; margin-top: 15px; padding-top: 12px; border-top: 1px solid var(--line); color: var(--red); font-size: 12px; font-weight: 750; text-decoration: none; }
        .product-open .material-icons-outlined { font-size: 18px; transition: transform .18s ease; }
        .product-open:hover .material-icons-outlined { transform: translateX(3px); }
        .catalog-notice { padding: 22px; border: 1px solid var(--line); border-radius: 10px; background: var(--soft); color: var(--muted); font-size: 13px; line-height: 1.6; }
        .services-section { background: var(--soft); }
        .services-inner { display: grid; grid-template-columns: .85fr 1.15fr; align-items: center; gap: 52px; width: min(1200px, 90%); margin: 0 auto; padding: 72px 0; }
        .services-intro p { max-width: 390px; margin: 14px 0 22px; color: var(--muted); font-size: 14px; line-height: 1.7; }
        .service-list { display: grid; grid-template-columns: repeat(2, 1fr); gap: 14px; }
        .service-card { min-height: 132px; padding: 20px; border: 1px solid var(--line); border-radius: 11px; background: white; }
        .service-card .trust-icon { margin-bottom: 13px; }
        .service-card h3 { margin: 0 0 7px; font-size: 14px; }
        .service-card p { margin: 0; color: var(--muted); font-size: 12px; line-height: 1.55; }
        .contact-band { display: flex; align-items: center; justify-content: space-between; gap: 24px; width: min(1200px, 90%); margin: 0 auto; padding: 48px 0; }
        .contact-band h2 { margin: 0; font-size: 25px; letter-spacing: -.5px; }
        .contact-band p { margin: 8px 0 0; color: var(--muted); font-size: 13px; }
        .button-red { background: var(--red); color: white; }
        .button-red:hover { background: var(--red-dark); }
        .site-footer { padding: 25px 5%; background: #202a35; color: #e8edf1; }
        .footer-inner { display: flex; width: min(1200px, 100%); align-items: center; justify-content: space-between; gap: 18px; margin: 0 auto; font-size: 11px; }
        .footer-inner p { margin: 0; color: #c1cbd4; }
        .footer-inner a { color: white; text-decoration: none; }
        @media (max-width: 760px) {
            .site-header { flex-wrap: wrap; gap: 10px; padding: 10px 5%; }
            .brand img { width: 41px; height: 41px; }
            .main-nav { width: 100%; justify-content: space-between; gap: 9px; }
            .main-nav a { font-size: 11px; }
            .main-nav .nav-login { padding: 8px 12px; }
            .hero { min-height: 475px; background-position: 58% center; }
            .hero::before { background: linear-gradient(90deg, rgba(47,17,27,.85), rgba(87,23,39,.57)); }
            .hero h1 { letter-spacing: -1.5px; }
            .hero-copy { max-width: 450px; font-size: 15px; }
            .trust-strip { grid-template-columns: 1fr; gap: 9px; margin-top: -22px; }
            .trust-item { min-height: 70px; padding: 13px; }
            .section { padding: 58px 0; }
            .section-heading { align-items: flex-start; flex-direction: column; gap: 13px; }
            .product-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
            .product-image { height: 145px; }
            .product-info { padding: 13px; }
            .product-info h3 { min-height: 58px; font-size: 13px; }
            .product-meta { align-items: flex-start; flex-direction: column; }
            .services-inner { grid-template-columns: 1fr; gap: 25px; padding: 58px 0; }
            .contact-band { align-items: flex-start; flex-direction: column; padding: 40px 0; }
            .footer-inner { align-items: flex-start; flex-direction: column; }
        }
        @media (max-width: 420px) {
            .brand-name { font-size: 15px; }
            .main-nav { gap: 5px; }
            .main-nav a { font-size: 10px; }
            .product-grid, .service-list { grid-template-columns: 1fr; }
            .product-image { height: 190px; }
        }
        @media (prefers-reduced-motion: reduce) {
            html { scroll-behavior: auto; }
            *, *::before, *::after { transition-duration: .01ms !important; }
        }
    </style>
</head>
<body>
    <header class="site-header">
        <a class="brand" href="#home" aria-label="MedCare Pharmacy home">
            <img src="images/logo.png" alt="">
            <span class="brand-copy">
                <span class="brand-name">MedCare Pharmacy</span>
                <span class="brand-caption">Josefina Farmacia</span>
            </span>
        </a>
        <nav class="main-nav" aria-label="Main navigation">
            <a href="#home">Home</a>
            <a href="#products">Products</a>
            <a href="#services">Services</a>
            <a href="#contact">Contact</a>
            <a class="nav-login" href="<?php echo htmlspecialchars($isCustomer ? $catalogUrl : $customerLoginUrl, ENT_QUOTES, 'UTF-8'); ?>">
                <?php echo $isCustomer ? 'Shop medicines' : 'Customer login'; ?>
            </a>
        </nav>
    </header>

    <main>
        <section class="hero" id="home">
            <div class="hero-content">
                <p class="hero-kicker">Your neighborhood pharmacy</p>
                <h1>Care for your health, made simpler.</h1>
                <p class="hero-copy">Browse our current selection of in-stock medicines and pharmacy essentials, then sign in to place your order.</p>
                <div class="hero-actions">
                    <a class="button button-light" href="<?php echo htmlspecialchars($catalogUrl, ENT_QUOTES, 'UTF-8'); ?>">Browse medicines <span class="material-icons-outlined" aria-hidden="true">arrow_forward</span></a>
                    <a class="button button-outline" href="#services">How we can help</a>
                </div>
            </div>
        </section>

        <div class="trust-strip" aria-label="Pharmacy services">
            <div class="trust-item"><span class="trust-icon" aria-hidden="true"><span class="material-icons-outlined">inventory_2</span></span><span><strong>Current inventory</strong><span>See products marked available in our catalog.</span></span></div>
            <div class="trust-item"><span class="trust-icon" aria-hidden="true"><span class="material-icons-outlined">shopping_bag</span></span><span><strong>Convenient ordering</strong><span>Sign in to browse and manage your orders.</span></span></div>
            <div class="trust-item"><span class="trust-icon" aria-hidden="true"><span class="material-icons-outlined">support_agent</span></span><span><strong>Pharmacy assistance</strong><span>Contact the pharmacy through your customer account.</span></span></div>
        </div>

        <section class="section" id="products">
            <div class="section-heading">
                <div>
                    <p class="eyebrow">From our inventory</p>
                    <h2>Available at the pharmacy</h2>
                    <p>These products are loaded from the active inventory and shown only while sellable stock is available.</p>
                </div>
                <a class="text-link" href="<?php echo htmlspecialchars($catalogUrl, ENT_QUOTES, 'UTF-8'); ?>">View full catalog <span class="material-icons-outlined" aria-hidden="true" style="font-size:17px;vertical-align:middle">arrow_forward</span></a>
            </div>
            <?php if ($catalogError !== ''): ?>
                <div class="catalog-notice" role="status"><?php echo htmlspecialchars($catalogError, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php elseif (!$products): ?>
                <div class="catalog-notice">There are no in-stock, non-prescription products to feature right now. Please check the catalog again soon.</div>
            <?php else: ?>
                <div class="product-grid">
                    <?php foreach ($products as $product):
                        $productName = (string)$product['product_name'];
                        $productImage = getHomepageProductImage((string)($product['image'] ?? ''), $productName);
                        $productUrl = $isCustomer
                            ? '../user/view_product.php?id=' . (int)$product['id']
                            : $customerLoginUrl;
                        $category = trim((string)($product['category'] ?? ''));
                    ?>
                        <article class="product-card">
                            <a class="product-image" href="<?php echo htmlspecialchars($productUrl, ENT_QUOTES, 'UTF-8'); ?>" aria-label="View <?php echo htmlspecialchars($productName, ENT_QUOTES, 'UTF-8'); ?>">
                                <img src="<?php echo htmlspecialchars($productImage, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($productName, ENT_QUOTES, 'UTF-8'); ?>" loading="lazy" onerror="this.onerror=null;this.src='images/logo.png'">
                            </a>
                            <div class="product-info">
                                <p class="product-category"><?php echo htmlspecialchars($category !== '' ? $category : 'Pharmacy essential', ENT_QUOTES, 'UTF-8'); ?></p>
                                <h3><?php echo htmlspecialchars($productName, ENT_QUOTES, 'UTF-8'); ?></h3>
                                <div class="product-meta">
                                    <span class="product-price">₱<?php echo number_format((float)$product['display_price'], 2); ?></span>
                                    <span class="stock-status"><span class="material-icons-outlined" aria-hidden="true">check_circle</span>In stock</span>
                                </div>
                                <a class="product-open" href="<?php echo htmlspecialchars($productUrl, ENT_QUOTES, 'UTF-8'); ?>">View product <span class="material-icons-outlined" aria-hidden="true">arrow_forward</span></a>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="services-section" id="services">
            <div class="services-inner">
                <div class="services-intro">
                    <p class="eyebrow">Here to help</p>
                    <h2>Pharmacy support, when you need it.</h2>
                    <p>Sign in to browse the full catalog, keep track of your orders, or contact the pharmacy about prescription requirements.</p>
                    <a class="button button-red" href="<?php echo htmlspecialchars($catalogUrl, ENT_QUOTES, 'UTF-8'); ?>">Continue to your account</a>
                </div>
                <div class="service-list">
                    <article class="service-card">
                        <span class="trust-icon" aria-hidden="true"><span class="material-icons-outlined">search</span></span>
                        <h3>Browse available products</h3>
                        <p>Explore active products and current availability from the pharmacy catalog.</p>
                    </article>
                    <article class="service-card">
                        <span class="trust-icon" aria-hidden="true"><span class="material-icons-outlined">medical_services</span></span>
                        <h3>Prescription assistance</h3>
                        <p>Prescription medicines require pharmacy review and an eligible prescription.</p>
                    </article>
                    <article class="service-card">
                        <span class="trust-icon" aria-hidden="true"><span class="material-icons-outlined">receipt_long</span></span>
                        <h3>Order updates</h3>
                        <p>Sign in to view order details and follow your order status.</p>
                    </article>
                    <article class="service-card">
                        <span class="trust-icon" aria-hidden="true"><span class="material-icons-outlined">support_agent</span></span>
                        <h3>Contact the pharmacy</h3>
                        <p>Customer support is available through your signed-in account.</p>
                    </article>
                </div>
            </div>
        </section>

        <section class="contact-band" id="contact">
            <div>
                <p class="eyebrow">Need help?</p>
                <h2>Talk to the pharmacy team.</h2>
                <p>Sign in to send a question or get help with an order.</p>
            </div>
            <a class="button button-red" href="<?php echo htmlspecialchars($isCustomer ? '../user/support.php' : $customerLoginUrl, ENT_QUOTES, 'UTF-8'); ?>">Get pharmacy support</a>
        </section>
    </main>

    <footer class="site-footer">
        <div class="footer-inner">
            <a class="brand" href="#home">
                <img src="images/logo.png" alt="" width="34" height="34">
                <span class="brand-copy">
                    <span class="brand-name" style="color:#fff">MedCare Pharmacy</span>
                    <span class="brand-caption" style="color:#c1cbd4">Josefina Farmacia</span>
                </span>
            </a>
            <p>&copy; <?php echo date('Y'); ?> MedCare Pharmacy. Please follow pharmacist guidance for medicine use.</p>
        </div>
    </footer>
</body>
</html>
