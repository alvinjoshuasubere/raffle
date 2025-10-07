<?php
// Get all winners
$winners = $conn->query("SELECT * FROM winners ORDER BY won_at DESC");
?>

<h1>Winners List</h1>

<?php display_message(); ?>

<?php if ($winners->num_rows > 0): ?>

<div style="margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center;">
    <div>
        <p style="color: #666;">Total Winners: <strong
                style="color: #DC143C;"><?php echo $winners->num_rows; ?></strong></p>
    </div>
    <div>
        <button onclick="exportWinnersPDF()" class="btn btn-success">Export to PDF</button>
    </div>
</div>

<table>
    <thead>
        <tr>
            <th>Number</th>
            <th>Name</th>
            <th>Barangay</th>
            <th>Prize</th>
            <th>Type</th>
            <th>Date Won</th>
        </tr>
    </thead>
    <tbody>
        <?php while ($winner = $winners->fetch_assoc()): ?>
        <tr>
            <td><strong style="color: #DC143C;"><?php echo htmlspecialchars($winner['number']); ?></strong></td>
            <td><?php echo htmlspecialchars($winner['name']); ?></td>
            <td><?php echo htmlspecialchars($winner['barangay']); ?></td>
            <td><strong><?php echo htmlspecialchars($winner['prize_name']); ?></strong></td>
            <td>
                <span class="badge badge-<?php echo strtolower($winner['prize_type']); ?>">
                    <?php echo $winner['prize_type']; ?>
                </span>
            </td>
            <td><?php echo date('M d, Y - h:i A', strtotime($winner['won_at'])); ?></td>
        </tr>
        <?php endwhile; ?>
    </tbody>
</table>

<div style="margin-top: 30px; padding: 20px; background: #f8f9fa; border-radius: 10px;">
    <h3 style="color: #DC143C; margin-bottom: 15px;">Winners Summary</h3>
    <?php
    // Get summary statistics
    $major_count = $conn->query("SELECT COUNT(*) as total FROM winners WHERE prize_type = 'Major'")->fetch_assoc()['total'];
    $minor_count = $conn->query("SELECT COUNT(*) as total FROM winners WHERE prize_type = 'Minor'")->fetch_assoc()['total'];
    $unique_winners = $conn->query("SELECT COUNT(DISTINCT number) as total FROM winners")->fetch_assoc()['total'];
    ?>
    <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px;">
        <div style="background: white; padding: 20px; border-radius: 5px; text-align: center;">
            <div style="font-size: 32px; font-weight: bold; color: #FFD700;"><?php echo $major_count; ?></div>
            <div style="color: #666; margin-top: 5px;">Major Prizes</div>
        </div>
        <div style="background: white; padding: 20px; border-radius: 5px; text-align: center;">
            <div style="font-size: 32px; font-weight: bold; color: #DC143C;"><?php echo $minor_count; ?></div>
            <div style="color: #666; margin-top: 5px;">Minor Prizes</div>
        </div>
        <div style="background: white; padding: 20px; border-radius: 5px; text-align: center;">
            <div style="font-size: 32px; font-weight: bold; color: #28a745;"><?php echo $unique_winners; ?></div>
            <div style="color: #666; margin-top: 5px;">Unique Winners</div>
        </div>
    </div>
</div>

<?php else: ?>
<div style="text-align: center; padding: 60px; background: #f8f9fa; border-radius: 10px;">
    <h2 style="color: #999; margin-bottom: 15px;">No Winners Yet</h2>
    <p style="color: #666; margin-bottom: 40px;">Start drawing winners from the Draw section!</p>
    <a href="index.php?page=draw" class="btn btn-primary" style="display:inline-block; margin-top:20px;">Go to Draw</a>
</div>

<?php endif; ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"></script>
<script>
function exportWinnersPDF() {
    const {
        jsPDF
    } = window.jspdf;
    const doc = new jsPDF();

    // Load logo image (must be base64 or a public URL)
    const logoUrl = 'Logo.png'; // Change to your logo path

    // Helper to convert image to base64 and then generate PDF
    function generatePDF(logoDataUrl) {
        // Add logo to upper left (x=10, y=8, width=22, height=22)
        if (logoDataUrl) {
            doc.addImage(logoDataUrl, 'PNG', 10, 8, 22, 22);
        }

        // Custom header
        doc.setFontSize(14);
        doc.text("City Government of Koronadal", 105, 15, {
            align: "center"
        });
        doc.setFontSize(12);
        doc.text("Charter Anniversary", 105, 23, {
            align: "center"
        });
        doc.setFontSize(12);
        doc.text("Raffle Winner List 2025", 105, 31, {
            align: "center"
        });

        // Get table data
        const rows = [];
        document.querySelectorAll("table tbody tr").forEach(tr => {
            const row = [];
            tr.querySelectorAll("td").forEach(td => {
                row.push(td.innerText);
            });
            rows.push(row);
        });

        // Get headers
        const headers = [];
        document.querySelectorAll("table thead th").forEach(th => {
            headers.push(th.innerText);
        });

        doc.autoTable({
            head: [headers],
            body: rows,
            startY: 38,
            styles: {
                fontSize: 9
            },
            headStyles: {
                fillColor: [0, 0, 0], // Black background
                textColor: [255, 255, 255], // White text
                fontStyle: 'bold'
            }
        });

        doc.save("list of winners raffle.pdf");
    }

    // Convert logo to base64 and then generate PDF
    const img = new window.Image();
    img.crossOrigin = "Anonymous";
    img.onload = function() {
        const canvas = document.createElement('canvas');
        canvas.width = img.width;
        canvas.height = img.height;
        const ctx = canvas.getContext('2d');
        ctx.drawImage(img, 0, 0);
        const dataURL = canvas.toDataURL('image/png');
        generatePDF(dataURL);
    };
    img.onerror = function() {
        // If logo fails to load, just generate PDF without logo
        generatePDF(null);
    };
    img.src = logoUrl;
}
</script>