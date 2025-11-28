<?php
require_once '../includes/functions.php';
require_admin_login();
global $conn;

// --- Fetch Book Copies for View/Search ---

    $search_query = trim($_GET['search'] ?? '');
    $sql = "
        SELECT 
            tbc.book_uid, tbc.status,
            tb.title, tb.author,
            l.library_name
        FROM 
            tbl_book_copies tbc
        JOIN 
            tbl_books tb ON tbc.book_id = tb.book_id
        LEFT JOIN
            tbl_libraries l ON tb.library_id = l.library_id
        WHERE 
            tbc.book_uid NOT LIKE '%-BASE' /* Exclude base UIDs */
            AND tb.total_quantity > 0       /* Only include copies of physical or both-type books */
    ";
    $params = [];
    $types = '';

    if (!is_super_admin($conn)) {
        // Filter by Admin's Library
        $admin_id = $_SESSION['admin_id'];
        $stmt_lib = $conn->prepare("SELECT library_id FROM tbl_admin WHERE admin_id = ?");
        $stmt_lib->bind_param("i", $admin_id);
        $stmt_lib->execute();
        $my_lib_id = $stmt_lib->get_result()->fetch_assoc()['library_id'] ?? 0;
        
        // Only filter if assigned to a specific library (ID > 0)
        if ($my_lib_id > 0) {
            $sql .= " AND tb.library_id = ?";
            $params[] = $my_lib_id;
            $types .= 'i';
        }
    }

    if (!empty($search_query)) {
        // Note: The AND operator is used here to combine the search filter with the permanent filters above
        $sql .= " AND (tbc.book_uid LIKE ? OR tb.title LIKE ? OR tb.author LIKE ? OR tbc.status LIKE ? OR l.library_name LIKE ?)";
        $search_term = "%" . $search_query . "%";
        $params = array_merge($params, [$search_term, $search_term, $search_term, $search_term, $search_term]);
        $types .= 'sssss';
    }

    $sql .= " ORDER BY tbc.book_uid ASC";

    $stmt = $conn->prepare($sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $copies_result = $stmt->get_result();

    admin_header('Book Copy Status');
    ?>

    <style>
        /* * Glass Components - Updated for White Transparency and Deep Black Contrast 
         */
        .glass-card {
            /* Increased opacity for brighter white glass */
            background: rgba(255, 255, 255, 0.85); 
            backdrop-filter: blur(25px);
            -webkit-backdrop-filter: blur(25px);
            /* Softer border */
            border: 1px solid rgba(255, 255, 255, 0.8);
            box-shadow: 0 10px 40px 0 rgba(0, 0, 0, 0.1);
            border-radius: 20px;
            padding: 30px;
            animation: fadeIn 0.5s ease-out;
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            /* Subtle divider */
            border-bottom: 1px solid rgba(0,0,0,0.08); 
            padding-bottom: 20px;
            flex-wrap: wrap;
            gap: 20px;
        }

        .header-text h2 {
            font-size: 1.5rem;
            font-weight: 700;
            /* Deep Black Text */
            color: #000000; 
            margin: 0 0 5px 0;
            display: flex; align-items: center; gap: 10px;
        }
        
        .header-text p {
            margin: 0;
            /* High Contrast Dark Gray */
            color: #333333; 
            font-size: 0.9rem;
        }
        
        .header-text i {
            /* Icon color set to deep black */
            color: #000000 !important; 
        }

        /* Search Bar Styling */
        .search-container {
            display: flex;
            gap: 10px;
            flex: 1;
            max-width: 500px;
        }

        .input-wrapper {
            position: relative;
            flex: 1;
        }

        .input-wrapper i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #666; /* Darker search icon */
        }

        .form-control {
            width: 100%;
            padding: 12px 12px 12px 45px;
            border: 1px solid rgba(0, 0, 0, 0.2); /* Darker border for contrast */
            background: rgba(255, 255, 255, 0.9); /* Almost opaque white background */
            border-radius: 12px;
            font-size: 0.95rem;
            /* Deep Black Text Input */
            color: #000000; 
            transition: all 0.3s;
            box-sizing: border-box; 
        }

        .form-control:focus {
            background: #ffffff;
            border-color: #000000; /* Black focus border */
            box-shadow: 0 0 0 4px rgba(0, 0, 0, 0.1);
            outline: none;
        }

        .btn-action {
            padding: 0 20px;
            border-radius: 12px;
            border: none;
            cursor: pointer;
            transition: 0.2s;
            color: white;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            height: 45px; 
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }

        /* Primary Search button is Deep Black */
        .btn-search { background: #000000; }
        .btn-search:hover { background: #333333; transform: translateY(-2px); }
        
        .btn-clear { background: #ef4444; }
        .btn-clear:hover { background: #dc2626; transform: translateY(-2px); }

        /* Glass Table */
        .table-container { 
            overflow-x: auto; 
            border-radius: 12px; 
            border: 1px solid rgba(0, 0, 0, 0.1); /* Subtle outline for the container */
        }
        .glass-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 0;
            background: rgba(255, 255, 255, 0.5); /* Table itself is slightly more transparent */
        }
        
        .glass-table th {
            /* Header background is subtle dark tint */
            background: rgba(0, 0, 0, 0.05);
            /* Deep Black Header Text */
            color: #000000; 
            font-weight: 700;
            padding: 16px;
            text-align: left;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            /* Remove internal border on header */
            border-bottom: 2px solid rgba(0, 0, 0, 0.1);
        }
        
        .glass-table td {
            padding: 16px;
            border-bottom: 1px solid rgba(0,0,0,0.05); /* Very light separator */
            /* Deep Black Cell Text */
            color: #111111; 
            font-size: 0.95rem;
            vertical-align: middle;
        }
        
        .glass-table tr:last-child td { border-bottom: none; }
        /* Hover effect is a very subtle highlight */
        .glass-table tr:hover { background: rgba(0,0,0,0.03); }

        /* Status Badges */
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            /* Add a small border for better definition against white background */
            border: 1px solid rgba(0,0,0,0.1); 
        }

        .status-badge::before {
            content: '';
            width: 6px; height: 6px;
            border-radius: 50%;
            background-color: currentColor;
        }
        
        /* Status colors remain distinctive */
        .status-avail { background: #dcfce7; color: #166534; }
        .status-issued { background: #fff7ed; color: #c2410c; }
        .status-reserved { background: #eff6ff; color: #1e40af; }
        .status-lost { background: #fee2e2; color: #991b1b; }
        .status-default { background: #f1f5f9; color: #475569; }

        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

        @media (max-width: 768px) {
            .card-header { flex-direction: column; align-items: flex-start; }
            .search-container { width: 100%; max-width: none; }
        }
    </style>

    <div class="glass-card">
        <div class="card-header">
            <div class="header-text">
                <!-- Icon is now deep black -->
                <h2><i class="fas fa-barcode"></i> Copy Status</h2>
                <p>Track availability of every individual book copy by its Unique ID.</p>
            </div>
            
            <form method="GET" class="search-container">
                <div class="input-wrapper">
                    <i class="fas fa-search"></i>
                    <input type="search" name="search" class="form-control" placeholder="Search UID, Title, Status..." value="<?php echo htmlspecialchars($search_query); ?>">
                </div>
                <!-- Search button is now deep black -->
                <button type="submit" class="btn-action btn-search">Search</button>
                <?php if (!empty($search_query)): ?>
                    <a href="book_copies.php" class="btn-action btn-clear"><i class="fas fa-times"></i></a>
                <?php endif; ?>
            </form>
        </div>

        <div class="table-container">
            <table class="glass-table">
                <thead>
                    <tr>
                        <th>Unique ID (UID)</th>
                        <th>Book Title</th>
                        <th>Author</th>
                        <th>Library</th>
                        <th>Current Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($copies_result->num_rows > 0): ?>
                        <?php while ($copy = $copies_result->fetch_assoc()): ?>
                            <?php
                                // Status classification logic remains the same
                                $status_class = 'status-default';
                                $status_label = $copy['status'];
                                
                                switch($copy['status']) {
                                    case 'Available': $status_class = 'status-avail'; break;
                                    case 'Issued': $status_class = 'status-issued'; break;
                                    case 'Reserved': $status_class = 'status-reserved'; break;
                                    case 'Lost': $status_class = 'status-lost'; break;
                                }
                            ?>
                            <tr>
                                <td>
                                    <!-- UID is explicitly deep black for visibility -->
                                    <strong style="font-family: 'Courier New', monospace; font-size: 1rem; color: #000000;">
                                        <?php echo htmlspecialchars($copy['book_uid']); ?>
                                    </strong>
                                </td>
                                <td>
                                    <!-- Title is dark gray for high contrast -->
                                    <div style="font-weight: 600; color: #111111;">
                                        <?php echo htmlspecialchars($copy['title']); ?>
                                    </div>
                                </td>
                                <td><?php echo htmlspecialchars($copy['author']); ?></td>
                                <td><?php echo htmlspecialchars($copy['library_name'] ?? 'N/A'); ?></td>
                                <td>
                                    <span class="status-badge <?php echo $status_class; ?>">
                                        <?php echo $status_label; ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" style="text-align: center; padding: 40px; color: #666666;">
                                <i class="fas fa-search" style="font-size: 2rem; margin-bottom: 10px; display: block; opacity: 0.5;"></i>
                                No book copies found matching your search.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

<?php
admin_footer();
close_db_connection($conn);
?>