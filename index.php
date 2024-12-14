<?php
session_start();

// Remove all login-related code
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', 'error.log');

// Keep XSS protection
function e($string) {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}
    
$conn = new mysqli("localhost", "root", "", "pos_system");
if ($conn->connect_error) {
    error_log("Connection failed: " . $conn->connect_error);
    die("Database connection error: " . $conn->connect_error);
}

// Optimize caching with error handling
$cache_file = 'cache/products.json';
try {
    if (file_exists($cache_file) && time() - filemtime($cache_file) < 300) {
        $products = json_decode(file_get_contents($cache_file), true);
        if ($products === null) {
            throw new Exception("Invalid cache data");
        }
    } else {
        $products = $conn->query("SELECT * FROM products")->fetch_all(MYSQLI_ASSOC);
        if (!is_dir('cache')) {
            mkdir('cache', 0777, true);
        }
        if (file_put_contents($cache_file, json_encode($products)) === false) {
            throw new Exception("Failed to write cache file");
        }
    }
} catch (Exception $e) {
    $products = $conn->query("SELECT * FROM products")->fetch_all(MYSQLI_ASSOC);
    error_log("Caching error: " . $e->getMessage());
}

$cart = [];
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!isset($_SESSION['cart'])) {
        $_SESSION['cart'] = [];
    }

    $cart = $_SESSION['cart'];

    if (isset($_POST['add'])) {
        $id = $_POST['id'];
        $stmt = $conn->prepare("SELECT * FROM products WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $product = $stmt->get_result()->fetch_assoc();
        if ($product && $product['stock'] > 0) {
            $cart[$id] = ($cart[$id] ?? 0) + 1;
        }
    } elseif (isset($_POST['remove'])) {
        $id = $_POST['id'];
        if (isset($cart[$id])) {
            $cart[$id]--;
            if ($cart[$id] <= 0) {
                unset($cart[$id]);
            }
        }
    } elseif (isset($_POST['checkout'])) {
        $valid = true;
        foreach ($cart as $id => $qty) {
            $stmt = $conn->prepare("SELECT stock FROM products WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            
            if ($result['stock'] < $qty) {
                $valid = false;
                echo "Not enough stock for product ID: $id";
                break;
            }
        }
        
        if ($valid) {
            foreach ($cart as $id => $qty) {
                $stmt = $conn->prepare("UPDATE products SET stock = stock - ? WHERE id = ?");
                $stmt->bind_param("ii", $qty, $id);
                $stmt->execute();
            }
            $cart = [];
            $_SESSION['cart'] = $cart;
        }
    } elseif (isset($_POST['view'])) {
        $id = $_POST['id'];
        // Add view logic here
    } elseif (isset($_POST['edit'])) {
        $id = $_POST['id'];
        $quantity = (int)$_POST['quantity'];
        
        if ($quantity > 0) {
            $stmt = $conn->prepare("SELECT stock FROM products WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            
            if ($result && $quantity <= $result['stock']) {
                $cart[$id] = $quantity;
            }
        } else {
            unset($cart[$id]);
        }
    } elseif (isset($_POST['delete'])) {
        $id = (int)$_POST['id'];
        try {
            $stmt = $conn->prepare("DELETE FROM products WHERE id = ?");
            $stmt->bind_param("i", $id);
            if (!$stmt->execute()) {
                throw new Exception("Failed to delete product");
            }
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        } catch (Exception $e) {
            $_SESSION['error'] = $e->getMessage();
        }
    } elseif (isset($_POST['edit_product'])) {
        $id = (int)$_POST['edit_id'];
        $name = $conn->real_escape_string($_POST['edit_name']);
        $price = floatval($_POST['edit_price']);
        $stock = intval($_POST['edit_stock']);
        
        $stmt = $conn->prepare("UPDATE products SET name = ?, price = ?, stock = ? WHERE id = ?");
        $stmt->bind_param("sdii", $name, $price, $stock, $id);
        if ($stmt->execute()) {
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        }
    } elseif (isset($_POST['new_product'])) {
        $name = $conn->real_escape_string($_POST['name']);
        $price = floatval($_POST['price']);
        $stock = intval($_POST['stock']);
        
        if ($conn->query("INSERT INTO products (name, price, stock) VALUES ('$name', $price, $stock)")) {
            // Redirect to refresh the page after successful insertion
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        } else {
            // Handle error (optional)
            echo "Error: " . $conn->error;
        }
    }

    $_SESSION['cart'] = $cart;
}


$total = 0;
foreach ($cart as $id => $qty) {
    $stmt = $conn->prepare("SELECT * FROM products WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $product = $stmt->get_result()->fetch_assoc();
    $total += $product['price'] * $qty;
}

// Add CSRF protection
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die('CSRF token validation failed');
    }
}

// Optimize queries
$stmt = $conn->prepare("SELECT * FROM products ORDER BY name");

// Example of organizing code into functions
function getProducts($conn) {
    $stmt = $conn->prepare("SELECT * FROM products ORDER BY name");
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

$products = getProducts($conn);

// Check if products are being fetched correctly
$products = $conn->query("SELECT * FROM products");
if (!$products) {
    die("Error fetching products: " . $conn->error);
}

// At the top of your file
if (!file_exists('styles.css')) {
    die("styles.css is missing");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>POS System</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Point of Sale System</h1>
            <div class="header-actions">
                <button class="btn btn-primary btn-new">
                    <i class="fas fa-plus"></i> New Product
                </button>
            </div>
        </div>
        
        <div class="grid">
            <div class="card">
                <div class="card-header">
                    <h2>Products</h2>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Price</th>
                            <th>Stock</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($product = $products->fetch_assoc()): ?>
                            <tr>
                                <td><?= htmlspecialchars($product['name']) ?></td>
                                <td>$<?= number_format($product['price'], 2) ?></td>
                                <td>
                                    <span class="stock-badge <?= $product['stock'] < 10 ? 'low-stock' : '' ?>">
                                        <?= $product['stock'] ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="crud-buttons">
                                        <button type="button" class="btn btn-primary btn-view" 
                                            data-id="<?= $product['id'] ?>"
                                            data-name="<?= htmlspecialchars($product['name']) ?>"
                                            data-price="<?= number_format($product['price'], 2) ?>"
                                            data-stock="<?= $product['stock'] ?>">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <button type="button" class="btn btn-warning btn-edit">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <form method="POST" style="display: inline-flex;">
                                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                            <input type="hidden" name="id" value="<?= $product['id'] ?>">
                                            <button type="submit" name="add" class="btn btn-success">
                                                <i class="fas fa-cart-plus"></i>
                                            </button>
                                            <button type="submit" name="delete" class="btn btn-danger">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>

            <div class="card">
                <div class="cart-section">
                    <h2>Shopping Cart</h2>
                    <div class="cart-items">
                        <?php if (empty($cart)): ?>
                            <div class="empty-cart">
                                <i class="fas fa-shopping-cart fa-3x"></i>
                                <p>Your cart is empty</p>
                            </div>
                        <?php else: ?>
                            <table>
                                <thead>
                                    <tr>
                                        <th>Item</th>
                                        <th>Qty</th>
                                        <th>Price</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($cart as $id => $qty): 
                                        $stmt = $conn->prepare("SELECT * FROM products WHERE id = ?");
                                        $stmt->bind_param("i", $id);
                                        $stmt->execute();
                                        $product = $stmt->get_result()->fetch_assoc();
                                    ?>
                                        <tr>
                                            <td><?= htmlspecialchars($product['name']) ?></td>
                                            <td><?= $qty ?></td>
                                            <td>$<?= number_format($product['price'] * $qty, 2) ?></td>
                                            <td>
                                                <form method="POST" class="quantity-form">
                                                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                                    <input type="hidden" name="id" value="<?= $id ?>">
                                                    <input type="number" name="quantity" value="<?= $qty ?>" min="0" max="<?= $product['stock'] ?>">
                                                    <button type="submit" name="edit"> Update</button>
                                                    <button type="submit" name="remove"> Remove</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <div class="total">
                                Total: $<?= number_format($total, 2) ?>
                            </div>
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                <button type="submit" name="checkout" class="btn-submit">Complete Checkout</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div id="newProductModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Add New Product</h2>
                <span class="close">&times;</span>
            </div>
            <form method="POST" class="modal-form">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <div class="form-group">
                    <label for="name">Product Name</label>
                    <input type="text" id="name" name="name" required>
                </div>
                <div class="form-group">
                    <label for="price">Price</label>
                    <input type="number" id="price" name="price" step="0.01" min="0" required>
                </div>
                <div class="form-group">
                    <label for="stock">Stock</label>
                    <input type="number" id="stock" name="stock" min="0" required>
                </div>
                <button type="submit" name="new_product" class="btn-submit">Add Product</button>
            </form>
        </div>
    </div>

    <div id="editProductModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Edit Product</h2>
                <span class="close-edit">&times;</span>
            </div>
            <form method="POST" class="modal-form">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" id="edit_id" name="edit_id">
                <div class="form-group">
                    <label for="edit_name">Product Name</label>
                    <input type="text" id="edit_name" name="edit_name" required>
                </div>
                <div class="form-group">
                    <label for="edit_price">Price</label>
                    <input type="number" id="edit_price" name="edit_price" step="0.01" min="0" required>
                </div>
                <div class="form-group">
                    <label for="edit_stock">Stock</label>
                    <input type="number" id="edit_stock" name="edit_stock" min="0" required>
                </div>
                <button type="submit" name="edit_product" class="btn-submit">Update Product</button>
            </form>
        </div>
    </div>

    <div id="viewProductModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Product Details</h2>
                <span class="close-view">&times;</span>
            </div>
            <div class="product-details">
                <div class="detail-group">
                    <label>Product Name:</label>
                    <p id="view_name"></p>
                </div>
                <div class="detail-group">
                    <label>Price:</label>
                    <p id="view_price"></p>
                </div>
                <div class="detail-group">
                    <label>Stock:</label>
                    <p id="view_stock"></p>
                </div>
            </div>
        </div>
    </div>

    <script>
        const modal = document.getElementById("newProductModal");
        const btn = document.querySelector(".btn-new");
        const span = document.querySelector(".close");

        btn.onclick = function() {
            modal.style.display = "block";
        }

        span.onclick = function() {
            modal.style.display = "none";
        }

        const editModal = document.getElementById("editProductModal");
        const editButtons = document.querySelectorAll(".btn-edit");
        const closeEdit = document.querySelector(".close-edit");

        editButtons.forEach(button => {
            button.onclick = function(e) {
                e.preventDefault();
                const row = this.closest('tr');
                const id = row.querySelector('input[name="id"]').value;
                const name = row.cells[0].textContent;
                const price = row.cells[1].textContent.replace('$', '');
                const stock = row.cells[2].textContent;

                document.getElementById('edit_id').value = id;
                document.getElementById('edit_name').value = name;
                document.getElementById('edit_price').value = price;
                document.getElementById('edit_stock').value = stock;

                editModal.style.display = "block";
            }
        });

        closeEdit.onclick = function() {
            editModal.style.display = "none";
        }

        const viewModal = document.getElementById("viewProductModal");
        const viewButtons = document.querySelectorAll(".btn-view");
        const closeView = document.querySelector(".close-view");

        viewButtons.forEach(button => {
            button.onclick = function() {
                const name = this.getAttribute('data-name');
                const price = '$' + this.getAttribute('data-price');
                const stock = this.getAttribute('data-stock');

                document.getElementById('view_name').textContent = name;
                document.getElementById('view_price').textContent = price;
                document.getElementById('view_stock').textContent = stock;

                viewModal.style.display = "block";
            }
        });

        closeView.onclick = function() {
            viewModal.style.display = "none";
        }

        window.onclick = function(event) {
            if (event.target == modal) {
                modal.style.display = "none";
            }
            if (event.target == editModal) {
                editModal.style.display = "none";
            }
            if (event.target == viewModal) {
                viewModal.style.display = "none";
            }
        }

        document.querySelectorAll('button[name="delete"]').forEach(button => {
            button.onclick = function(e) {
                if (!confirm('Are you sure you want to delete this product?')) {
                    e.preventDefault();
                }
            }
        });
    </script>
</body>
</html>
