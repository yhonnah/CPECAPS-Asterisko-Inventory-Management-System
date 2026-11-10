import os
import pymysql
import ssl

ssl_ctx = ssl.create_default_context()
ssl_ctx.check_hostname = False
ssl_ctx.verify_mode = ssl.CERT_NONE

host = os.getenv('DB_HOST', 'aste-inventory-aste-inventory-system.j.aivencloud.com')
port = int(os.getenv('DB_PORT', 13670))
user = os.getenv('DB_USER', 'avnadmin')
password = os.getenv('DB_PASS', 'AVNS_5-LQ3ixBakUHi93eYVK')
database = os.getenv('DB_NAME', 'aste-inventory')

conn = pymysql.connect(
    host=host,
    port=port,
    user=user,
    password=password,
    database=database,
    ssl=ssl_ctx,
    connect_timeout=15,
    autocommit=False
)

try:
    print("Beginning migration push to Aiven Cloud Database...")
    with conn.cursor() as cur:
        # Disable foreign key checks for clean truncation and re-indexing
        cur.execute("SET FOREIGN_KEY_CHECKS = 0;")

        # 1. PURGE AUDIT LOGS
        print("1. Purging audit logs...")
        cur.execute("TRUNCATE TABLE audit_logs;")
        print("   ✓ Audit trail table truncated.")

        # 2. UPDATE SUPPLIERS
        print("2. Pushing 7 Cafe Suppliers...")
        cur.execute("TRUNCATE TABLE suppliers;")
        suppliers = [
            (1, 'Benguet Highlands Coffee Roasters', 'orders@benghighlandscoffee.ph | +63 917 555 1021', 3, 1),
            (2, 'Valley Fresh Dairy Farm', 'supply@valleydairy.ph | +63 918 555 3302', 1, 1),
            (3, 'Oatside & Plant Milk Distro', 'orders@oatsidedistro.ph | +63 920 555 8891', 2, 1),
            (4, 'Artisan Gourmet Flavors & Syrups', 'orders@artisangourmet.ph | +63 922 555 7714', 2, 1),
            (5, 'Kyoto Uji Matcha & Tea Imports', 'wholesale@kyototea.ph | +63 917 555 9901', 4, 1),
            (6, 'La Brioche Bakery & Commissary', 'dispatch@labriochepastry.ph | +63 919 555 4410', 1, 1),
            (7, 'EcoPack Cafe Disposables Co.', 'sales@ecopackmanila.ph | +63 925 555 6620', 3, 1)
        ]
        cur.executemany(
            "INSERT INTO suppliers (id, name, contact_details, lead_time_days, is_active) VALUES (%s, %s, %s, %s, %s)",
            suppliers
        )
        print("   ✓ 7 suppliers inserted.")

        # 3. UPDATE STOCK ITEMS (RAW INGREDIENTS & PACKAGING)
        print("3. Pushing 21 Raw Stock Items & Ingredients...")
        cur.execute("TRUNCATE TABLE stock_items;")
        stock_items = [
            (1, 'Houseblend Coffee Beans', 'Beans', 18.00, 'kg', 3.00, 850.00, 1, 1),
            (2, 'Fresh Whole Milk', 'Dairy', 30.00, 'L', 6.00, 105.00, 2, 1),
            (3, 'Barista Oat Milk', 'Dairy', 16.00, 'L', 3.00, 165.00, 3, 1),
            (4, 'Sweetened Condensed Milk', 'Dairy', 12.00, 'kg', 2.00, 90.00, 2, 1),
            (5, 'Pure Coconut Water', 'General', 12.00, 'L', 2.50, 85.00, 4, 1),
            (6, 'Valencia Orange Puree', 'Syrups', 6.00, 'L', 1.00, 260.00, 4, 1),
            (7, 'Gourmet Caramel Syrup', 'Syrups', 6.00, 'L', 1.00, 320.00, 4, 1),
            (8, 'White Chocolate Ganache Sauce', 'Syrups', 8.00, 'kg', 1.50, 380.00, 4, 1),
            (9, 'French Lavender Flower Extract', 'Syrups', 4.00, 'L', 0.80, 350.00, 4, 1),
            (10, 'Wild Strawberry Fruit Puree', 'Syrups', 8.00, 'L', 1.50, 280.00, 4, 1),
            (11, 'New York Cheesecake Sauce Base', 'Syrups', 8.00, 'kg', 1.50, 390.00, 4, 1),
            (12, 'Artisan Horchata Spiced Rice Base', 'General', 10.00, 'L', 2.00, 180.00, 4, 1),
            (13, 'Ceremonial Uji Matcha Powder', 'Tea', 4.00, 'kg', 0.50, 1350.00, 5, 1),
            (14, 'Roasted Shizuoka Hojicha Powder', 'Tea', 4.00, 'kg', 0.50, 1250.00, 5, 1),
            (15, 'Artisan Butter Croissant', 'Pastry', 30.00, 'pcs', 5.00, 55.00, 6, 1),
            (16, 'Pain Au Chocolat', 'Pastry', 30.00, 'pcs', 5.00, 65.00, 6, 1),
            (17, 'Sticky Glazed Cinnamon Roll', 'Pastry', 30.00, 'pcs', 5.00, 60.00, 6, 1),
            (18, 'Takeaway Cups (Dude 8oz)', 'Packaging', 300.00, 'pcs', 50.00, 3.50, 7, 1),
            (19, 'Takeaway Cups (Pare 12oz)', 'Packaging', 300.00, 'pcs', 50.00, 4.20, 7, 1),
            (20, 'Takeaway Cups (Chong 16oz)', 'Packaging', 300.00, 'pcs', 50.00, 4.80, 7, 1),
            (21, 'Universal Hot/Cold Sipper Lids', 'Packaging', 600.00, 'pcs', 100.00, 1.80, 7, 1)
        ]
        cur.executemany(
            "INSERT INTO stock_items (id, name, category, quantity, unit, reorder_point, unit_cost, supplier_id, is_active) VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s)",
            stock_items
        )
        print("   ✓ 21 stock items inserted.")

        # 4. UPDATE SUPPLIER OCR MAPPINGS
        print("4. Pushing 21 Supplier OCR Mappings...")
        cur.execute("TRUNCATE TABLE supplier_mappings;")
        mappings = [
            (1, 1, 'BH-BEANS-01', 'Houseblend Espresso Beans 1kg Bag'),
            (2, 2, 'VF-MILK-WHOLE', 'Valley Fresh Whole Cow Milk 1L'),
            (2, 4, 'VF-COND-CAN', 'Valley Sweetened Condensed Milk 1kg'),
            (3, 3, 'OAT-BARISTA-1L', 'Oatside Barista Blend Oat Milk 1L'),
            (4, 5, 'COCO-WATER-1L', 'Pure Chilled Coconut Water 1L Tetra'),
            (4, 6, 'PUREE-ORANGE-1L', 'Valencia Orange Puree 1L Bottle'),
            (4, 7, 'SYR-CARAMEL-1L', 'Artisan Gourmet Caramel Syrup 1L'),
            (4, 8, 'SAUCE-WHITECHOC', 'White Chocolate Ganache Syrup 1kg'),
            (4, 9, 'EXT-LAVENDER', 'French Lavender Flower Floral Extract 1L'),
            (4, 10, 'PUREE-STRAWBERRY', 'Wild Strawberry Puree Syrup 1L'),
            (4, 11, 'SAUCE-CHEESECAKE', 'New York Style Cheesecake Cream Sauce 1kg'),
            (4, 12, 'BASE-HORCHATA-1L', 'Spiced Horchata Traditional Rice Extract 1L'),
            (5, 13, 'KYOTO-MATCHA-CER', 'Kyoto Uji Ceremonial Grade Matcha 1kg'),
            (5, 14, 'KYOTO-HOJICHA-01', 'Roasted Shizuoka Hojicha Fine Powder 1kg'),
            (6, 15, 'BAKE-CROISSANT-FR', 'Fresh Butter Croissant Tray 10s'),
            (6, 16, 'BAKE-PAIN-CHOCO', 'French Pain Au Chocolat Pastry Tray 10s'),
            (6, 17, 'BAKE-CINN-ROLL', 'Sticky Glazed Cinnamon Rolls 10s'),
            (7, 18, 'CUP-DUDE-8OZ', 'Asterisko Embossed Paper Cups 8oz (50s)'),
            (7, 19, 'CUP-PARE-12OZ', 'Asterisko Embossed Paper Cups 12oz (50s)'),
            (7, 20, 'CUP-CHONG-16OZ', 'Asterisko Embossed Paper Cups 16oz (50s)'),
            (7, 21, 'LID-SIPPER-UNI', 'Universal Eco Sipper Lids 90mm (100s)')
        ]
        cur.executemany(
            "INSERT INTO supplier_mappings (supplier_id, internal_item_id, supplier_item_id, supplier_item_name) VALUES (%s, %s, %s, %s)",
            mappings
        )
        print("   ✓ 21 supplier mappings inserted.")

        # 5. UPDATE MENU ITEMS & RECIPES
        print("5. Pushing 55 Asterisko Menu Items & Recipe Ingredient Deductions...")
        cur.execute("TRUNCATE TABLE menu_item_ingredients;")
        cur.execute("TRUNCATE TABLE menu_items;")

        c8 = 18; c12 = 19; c16 = 20; lid = 21

        menu_items_data = [
            # AMERICANO SERIES
            (1, 'Americano (Dude)', 'Americano Series', 140.00, 'Double espresso balanced with hot or chilled filtered water (8 oz)', 'coffee', [(1, 0.018, 'kg'), (c8, 1.0, 'pcs'), (lid, 1.0, 'pcs')]),
            (2, 'Americano (Pare)', 'Americano Series', 150.00, 'Rich double espresso balanced with hot or chilled filtered water (12 oz)', 'coffee', [(1, 0.020, 'kg'), (c12, 1.0, 'pcs'), (lid, 1.0, 'pcs')]),
            (3, 'Americano (Chong)', 'Americano Series', 165.00, 'Extra-shot espresso balanced with hot or chilled filtered water (16 oz)', 'coffee', [(1, 0.024, 'kg'), (c16, 1.0, 'pcs'), (lid, 1.0, 'pcs')]),
            (4, 'Coco Americano (Dude)', 'Americano Series', 165.00, 'Refreshing coconut water topped with espresso (8 oz)', 'ice-coffee', [(1, 0.018, 'kg'), (5, 0.15, 'L'), (c8, 1.0, 'pcs'), (lid, 1.0, 'pcs')]),
            (5, 'Coco Americano (Pare)', 'Americano Series', 175.00, 'Refreshing coconut water topped with espresso (12 oz)', 'ice-coffee', [(1, 0.020, 'kg'), (5, 0.20, 'L'), (c12, 1.0, 'pcs'), (lid, 1.0, 'pcs')]),
            (6, 'Coco Americano (Chong)', 'Americano Series', 185.00, 'Refreshing coconut water topped with espresso (16 oz)', 'ice-coffee', [(1, 0.024, 'kg'), (5, 0.25, 'L'), (c16, 1.0, 'pcs'), (lid, 1.0, 'pcs')]),
            (7, 'Orange Americano (Dude)', 'Americano Series', 165.00, 'Tangy orange puree layered with fresh espresso (8 oz)', 'ice-coffee', [(1, 0.018, 'kg'), (6, 0.03, 'L'), (c8, 1.0, 'pcs'), (lid, 1.0, 'pcs')]),
            (8, 'Orange Americano (Pare)', 'Americano Series', 175.00, 'Tangy orange puree layered with fresh espresso (12 oz)', 'ice-coffee', [(1, 0.020, 'kg'), (6, 0.04, 'L'), (c12, 1.0, 'pcs'), (lid, 1.0, 'pcs')]),
            (9, 'Orange Americano (Chong)', 'Americano Series', 185.00, 'Tangy orange puree layered with fresh espresso (16 oz)', 'ice-coffee', [(1, 0.024, 'kg'), (6, 0.05, 'L'), (c16, 1.0, 'pcs'), (lid, 1.0, 'pcs')]),

            # CLASSICS
            (10, 'Latte (Dude)', 'Classics', 160.00, 'Espresso with silky textured milk (8 oz)', 'latte', [(1, 0.018, 'kg'), (2, 0.16, 'L'), (c8, 1.0, 'pcs'), (lid, 1.0, 'pcs')]),
            (11, 'Latte (Pare)', 'Classics', 170.00, 'Espresso with silky textured milk (12 oz)', 'latte', [(1, 0.020, 'kg'), (2, 0.22, 'L'), (c12, 1.0, 'pcs'), (lid, 1.0, 'pcs')]),
            (12, 'Latte (Chong)', 'Classics', 180.00, 'Espresso with silky textured milk (16 oz)', 'latte', [(1, 0.024, 'kg'), (2, 0.28, 'L'), (c16, 1.0, 'pcs'), (lid, 1.0, 'pcs')]),
            (13, 'Caramel Latte (Dude)', 'Classics', 175.00, 'Espresso & silky milk infused with caramel (8 oz)', 'latte', [(1, 0.018, 'kg'), (2, 0.16, 'L'), (7, 0.02, 'L'), (c8, 1.0, 'pcs'), (lid, 1.0, 'pcs')]),
            (14, 'Caramel Latte (Pare)', 'Classics', 185.00, 'Espresso & silky milk infused with caramel (12 oz)', 'latte', [(1, 0.020, 'kg'), (2, 0.22, 'L'), (7, 0.03, 'L'), (c12, 1.0, 'pcs'), (lid, 1.0, 'pcs')]),
            (15, 'Caramel Latte (Chong)', 'Classics', 195.00, 'Espresso & silky milk infused with caramel (16 oz)', 'latte', [(1, 0.024, 'kg'), (2, 0.28, 'L'), (7, 0.04, 'L'), (c16, 1.0, 'pcs'), (lid, 1.0, 'pcs')]),
            (16, 'White Mocha (Dude)', 'Classics', 195.00, 'Decadent white chocolate sauce blended with espresso & milk (8 oz)', 'latte', [(1, 0.018, 'kg'), (2, 0.16, 'L'), (8, 0.025, 'kg'), (c8, 1.0, 'pcs'), (lid, 1.0, 'pcs')]),
            (17, 'White Mocha (Pare)', 'Classics', 205.00, 'Decadent white chocolate sauce blended with espresso & milk (12 oz)', 'latte', [(1, 0.020, 'kg'), (2, 0.22, 'L'), (8, 0.035, 'kg'), (c12, 1.0, 'pcs'), (lid, 1.0, 'pcs')]),
            (18, 'White Mocha (Chong)', 'Classics', 215.00, 'Decadent white chocolate sauce blended with espresso & milk (16 oz)', 'latte', [(1, 0.024, 'kg'), (2, 0.28, 'L'), (8, 0.045, 'kg'), (c16, 1.0, 'pcs'), (lid, 1.0, 'pcs')]),
            (19, 'Spanish Latte (Dude)', 'Classics', 170.00, 'Sweetened condensed milk blend with rich espresso (8 oz)', 'latte', [(1, 0.018, 'kg'), (2, 0.14, 'L'), (4, 0.025, 'kg'), (c8, 1.0, 'pcs'), (lid, 1.0, 'pcs')]),
            (20, 'Spanish Latte (Pare)', 'Classics', 180.00, 'Sweetened condensed milk blend with rich espresso (12 oz)', 'latte', [(1, 0.020, 'kg'), (2, 0.20, 'L'), (4, 0.035, 'kg'), (c12, 1.0, 'pcs'), (lid, 1.0, 'pcs')]),
            (21, 'Spanish Latte (Chong)', 'Classics', 190.00, 'Sweetened condensed milk blend with rich espresso (16 oz)', 'latte', [(1, 0.024, 'kg'), (2, 0.25, 'L'), (4, 0.045, 'kg'), (c16, 1.0, 'pcs'), (lid, 1.0, 'pcs')]),

            # SIGNATURE LATTES
            (22, 'Lavender Latte (Dude)', 'Signature Lattes', 170.00, 'Floral lavender tea, espresso & milk (8 oz)', 'latte', [(1, 0.018, 'kg'), (2, 0.15, 'L'), (9, 0.02, 'L'), (c8, 1.0, 'pcs'), (lid, 1.0, 'pcs')]),
            (23, 'Lavender Latte (Pare)', 'Signature Lattes', 180.00, 'Floral lavender tea, espresso & milk (12 oz)', 'latte', [(1, 0.020, 'kg'), (2, 0.20, 'L'), (9, 0.03, 'L'), (c12, 1.0, 'pcs'), (lid, 1.0, 'pcs')]),
            (24, 'Lavender Latte (Chong)', 'Signature Lattes', 190.00, 'Floral lavender tea, espresso & milk (16 oz)', 'latte', [(1, 0.024, 'kg'), (2, 0.25, 'L'), (9, 0.04, 'L'), (c16, 1.0, 'pcs'), (lid, 1.0, 'pcs')]),
            (25, 'Meiji Latte (Dude)', 'Signature Lattes', 190.00, 'Japanese strawberry, espresso & milk (8 oz)', 'latte', [(1, 0.018, 'kg'), (2, 0.15, 'L'), (10, 0.025, 'L'), (c8, 1.0, 'pcs'), (lid, 1.0, 'pcs')]),
            (26, 'Meiji Latte (Pare)', 'Signature Lattes', 200.00, 'Japanese strawberry, espresso & milk (12 oz)', 'latte', [(1, 0.020, 'kg'), (2, 0.20, 'L'), (10, 0.035, 'L'), (c12, 1.0, 'pcs'), (lid, 1.0, 'pcs')]),
            (27, 'Meiji Latte (Chong)', 'Signature Lattes', 210.00, 'Japanese strawberry, espresso & milk (16 oz)', 'latte', [(1, 0.024, 'kg'), (2, 0.25, 'L'), (10, 0.045, 'L'), (c16, 1.0, 'pcs'), (lid, 1.0, 'pcs')]),
            (28, 'Cinnamon Bun Latte (Dude)', 'Signature Lattes', 195.00, 'White choco, cinnamon spice, espresso & milk (8 oz)', 'latte', [(1, 0.018, 'kg'), (2, 0.15, 'L'), (8, 0.025, 'kg'), (c8, 1.0, 'pcs'), (lid, 1.0, 'pcs')]),
            (29, 'Cinnamon Bun Latte (Pare)', 'Signature Lattes', 205.00, 'White choco, cinnamon spice, espresso & milk (12 oz)', 'latte', [(1, 0.020, 'kg'), (2, 0.20, 'L'), (8, 0.035, 'kg'), (c12, 1.0, 'pcs'), (lid, 1.0, 'pcs')]),
            (30, 'Cinnamon Bun Latte (Chong)', 'Signature Lattes', 215.00, 'White choco, cinnamon spice, espresso & milk (16 oz)', 'latte', [(1, 0.024, 'kg'), (2, 0.25, 'L'), (8, 0.045, 'kg'), (c16, 1.0, 'pcs'), (lid, 1.0, 'pcs')]),
            (31, 'Sheeshcake Latte (Dude)', 'Signature Lattes', 175.00, 'Cheesecake infusion, espresso & milk (8 oz)', 'latte', [(1, 0.018, 'kg'), (2, 0.15, 'L'), (11, 0.025, 'kg'), (c8, 1.0, 'pcs'), (lid, 1.0, 'pcs')]),
            (32, 'Sheeshcake Latte (Pare)', 'Signature Lattes', 185.00, 'Cheesecake infusion, espresso & milk (12 oz)', 'latte', [(1, 0.020, 'kg'), (2, 0.20, 'L'), (11, 0.035, 'kg'), (c12, 1.0, 'pcs'), (lid, 1.0, 'pcs')]),
            (33, 'Sheeshcake Latte (Chong)', 'Signature Lattes', 195.00, 'Cheesecake infusion, espresso & milk (16 oz)', 'latte', [(1, 0.024, 'kg'), (2, 0.25, 'L'), (11, 0.045, 'kg'), (c16, 1.0, 'pcs'), (lid, 1.0, 'pcs')]),
            (34, 'Dirty Horchata (Dude)', 'Signature Lattes', 180.00, 'Spiced horchata base topped with espresso (8 oz)', 'latte', [(1, 0.018, 'kg'), (12, 0.15, 'L'), (c8, 1.0, 'pcs'), (lid, 1.0, 'pcs')]),
            (35, 'Dirty Horchata (Pare)', 'Signature Lattes', 190.00, 'Spiced horchata base topped with espresso (12 oz)', 'latte', [(1, 0.020, 'kg'), (12, 0.20, 'L'), (c12, 1.0, 'pcs'), (lid, 1.0, 'pcs')]),
            (36, 'Dirty Horchata (Chong)', 'Signature Lattes', 200.00, 'Spiced horchata base topped with espresso (16 oz)', 'latte', [(1, 0.024, 'kg'), (12, 0.25, 'L'), (c16, 1.0, 'pcs'), (lid, 1.0, 'pcs')]),

            # NON-COFFEE
            (37, 'Oatchata (Dude)', 'Non-Coffee', 160.00, 'Creamy oat milk horchata with cinnamon (8 oz)', 'ice-coffee', [(3, 0.16, 'L'), (12, 0.05, 'L'), (c8, 1.0, 'pcs'), (lid, 1.0, 'pcs')]),
            (38, 'Oatchata (Pare)', 'Non-Coffee', 170.00, 'Creamy oat milk horchata with cinnamon (12 oz)', 'ice-coffee', [(3, 0.22, 'L'), (12, 0.07, 'L'), (c12, 1.0, 'pcs'), (lid, 1.0, 'pcs')]),
            (39, 'Oatchata (Chong)', 'Non-Coffee', 180.00, 'Creamy oat milk horchata with cinnamon (16 oz)', 'ice-coffee', [(3, 0.28, 'L'), (12, 0.09, 'L'), (c16, 1.0, 'pcs'), (lid, 1.0, 'pcs')]),
            (40, 'White Chocolate (Dude)', 'Non-Coffee', 170.00, 'Steamed or iced velvety white chocolate with fresh milk (8 oz)', 'latte', [(2, 0.16, 'L'), (8, 0.03, 'kg'), (c8, 1.0, 'pcs'), (lid, 1.0, 'pcs')]),
            (41, 'White Chocolate (Pare)', 'Non-Coffee', 180.00, 'Steamed or iced velvety white chocolate with fresh milk (12 oz)', 'latte', [(2, 0.22, 'L'), (8, 0.04, 'kg'), (c12, 1.0, 'pcs'), (lid, 1.0, 'pcs')]),
            (42, 'White Chocolate (Chong)', 'Non-Coffee', 190.00, 'Steamed or iced velvety white chocolate with fresh milk (16 oz)', 'latte', [(2, 0.28, 'L'), (8, 0.05, 'kg'), (c16, 1.0, 'pcs'), (lid, 1.0, 'pcs')]),
            (43, 'Strawberry Cheesecake (Dude)', 'Non-Coffee', 185.00, 'Strawberry puree layered with creamy cheesecake milk (8 oz)', 'ice-coffee', [(2, 0.15, 'L'), (10, 0.03, 'L'), (11, 0.025, 'kg'), (c8, 1.0, 'pcs'), (lid, 1.0, 'pcs')]),
            (44, 'Strawberry Cheesecake (Pare)', 'Non-Coffee', 195.00, 'Strawberry puree layered with creamy cheesecake milk (12 oz)', 'ice-coffee', [(2, 0.20, 'L'), (10, 0.04, 'L'), (11, 0.035, 'kg'), (c12, 1.0, 'pcs'), (lid, 1.0, 'pcs')]),
            (45, 'Strawberry Cheesecake (Chong)', 'Non-Coffee', 205.00, 'Strawberry puree layered with creamy cheesecake milk (16 oz)', 'ice-coffee', [(2, 0.25, 'L'), (10, 0.05, 'L'), (11, 0.045, 'kg'), (c16, 1.0, 'pcs'), (lid, 1.0, 'pcs')]),

            # MATCHA & HOJICHA
            (46, 'Matcha (Dude)', 'Matcha & Hojicha', 185.00, 'Uji ceremonial green tea matcha with textured milk (8 oz)', 'latte', [(13, 0.005, 'kg'), (2, 0.16, 'L'), (c8, 1.0, 'pcs'), (lid, 1.0, 'pcs')]),
            (47, 'Matcha (Pare)', 'Matcha & Hojicha', 195.00, 'Uji ceremonial green tea matcha with textured milk (12 oz)', 'latte', [(13, 0.007, 'kg'), (2, 0.22, 'L'), (c12, 1.0, 'pcs'), (lid, 1.0, 'pcs')]),
            (48, 'Strawberry Matcha (Dude)', 'Matcha & Hojicha', 210.00, 'Layered strawberry puree, milk, and rich matcha (8 oz)', 'ice-coffee', [(13, 0.005, 'kg'), (2, 0.14, 'L'), (10, 0.03, 'L'), (c8, 1.0, 'pcs'), (lid, 1.0, 'pcs')]),
            (49, 'Strawberry Matcha (Pare)', 'Matcha & Hojicha', 220.00, 'Layered strawberry puree, milk, and rich matcha (12 oz)', 'ice-coffee', [(13, 0.007, 'kg'), (2, 0.20, 'L'), (10, 0.04, 'L'), (c12, 1.0, 'pcs'), (lid, 1.0, 'pcs')]),
            (50, 'Hojicha (Dude)', 'Matcha & Hojicha', 185.00, 'Roasted Japanese green tea latte with nutty aroma (8 oz)', 'latte', [(14, 0.005, 'kg'), (2, 0.16, 'L'), (c8, 1.0, 'pcs'), (lid, 1.0, 'pcs')]),
            (51, 'Hojicha (Pare)', 'Matcha & Hojicha', 195.00, 'Roasted Japanese green tea latte with nutty aroma (12 oz)', 'latte', [(14, 0.007, 'kg'), (2, 0.22, 'L'), (c12, 1.0, 'pcs'), (lid, 1.0, 'pcs')]),

            # PASTRIES
            (52, 'Croissant', 'Pastries', 120.00, 'Flaky, butter-layered golden French croissant baked daily', 'coffee', [(15, 1.0, 'pcs')]),
            (53, 'Pain Au Chocolat', 'Pastries', 135.00, 'Traditional French pastry filled with rich dark chocolate', 'coffee', [(16, 1.0, 'pcs')]),
            (54, 'Sticky Cinnamon Roll', 'Pastries', 130.00, 'Warm spiced roll drizzled with sweet sticky glaze', 'coffee', [(17, 1.0, 'pcs')]),

            # ADD-ON
            (55, 'Sub-Oat Milk (Add-on)', 'Add-ons', 20.00, 'Substitute dairy milk with creamy barista oat milk', 'coffee', [(3, 0.18, 'L')])
        ]

        menus_to_insert = []
        recipes_to_insert = []
        for m_id, name, cat, price, desc, icon, ings in menu_items_data:
            menus_to_insert.append((m_id, name, cat, price, desc, icon, 1))
            for stock_id, qty, unit in ings:
                recipes_to_insert.append((m_id, stock_id, qty, unit))

        cur.executemany(
            "INSERT INTO menu_items (id, name, category, price, description, icon, is_active) VALUES (%s, %s, %s, %s, %s, %s, %s)",
            menus_to_insert
        )
        cur.executemany(
            "INSERT INTO menu_item_ingredients (menu_item_id, stock_item_id, quantity_required, unit) VALUES (%s, %s, %s, %s)",
            recipes_to_insert
        )
        print("   ✓ 55 menu items and " + str(len(recipes_to_insert)) + " ingredient recipes inserted.")

        # 6. BUMP CATALOG CACHE VERSION
        print("6. Bumping catalog cache version in Aiven DB...")
        cur.execute("INSERT INTO system_cache_meta (meta_key, meta_value) VALUES ('catalog_version', 'v_new_menu') ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value);")
        print("   ✓ Cache version updated.")

        # Re-enable foreign key checks and commit
        cur.execute("SET FOREIGN_KEY_CHECKS = 1;")
        conn.commit()
        print("\n========================================================")
        print(" MIGRATION COMMITTED SUCCESSFULLY TO AIVEN CLOUD MYSQL!")
        print("========================================================")

except Exception as e:
    conn.rollback()
    print("\n[ERROR] Migration failed and was rolled back:", e)
    raise
finally:
    conn.close()
