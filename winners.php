<?php
$stmt_w = $conn->prepare("SELECT * FROM winners WHERE event_id = ? ORDER BY won_at DESC");
$stmt_w->bind_param("i", $current_event_id);
$stmt_w->execute();
$winners = $stmt_w->get_result();
?>

<h1>Winners List</h1>

<?php display_message(); ?>

<?php if ($winners->num_rows > 0): ?>

<div style="margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center;">
    <div>
        <p style="color: #6b7280;">Total Winners: <strong style="color: #ec4899;"><?php echo $winners->num_rows; ?></strong></p>
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
            <th>Date Won</th>
        </tr>
    </thead>
    <tbody>
        <?php while ($winner = $winners->fetch_assoc()): ?>
        <tr>
            <td><strong style="color: #f472b6;"><?php echo htmlspecialchars($winner['number']); ?></strong></td>
            <td><?php echo htmlspecialchars($winner['name']); ?></td>
            <td><?php echo htmlspecialchars($winner['barangay']); ?></td>
            <td>
                <?php if (!empty($winner['prize_name'])): ?>
                <span class="badge <?php echo $winner['prize_type'] === 'Major' ? 'badge-major' : 'badge-minor'; ?>"><?php echo htmlspecialchars($winner['prize_name']); ?></span>
                <?php else: ?>
                <span style="color: #c4b5c0;">—</span>
                <?php endif; ?>
            </td>
            <td><?php echo date('M d, Y - h:i A', strtotime($winner['won_at'])); ?></td>
        </tr>
        <?php endwhile; ?>
    </tbody>
</table>

<div style="margin-top: 30px; padding: 20px; background: #faf5f7; border-radius: 12px; border: 1px solid rgba(0,0,0,0.04);">
    <h3 style="color: #ec4899; margin-bottom: 15px;">Winners Summary</h3>
    <?php
    $total = $conn->prepare("SELECT COUNT(*) as total FROM winners WHERE event_id = ?");
    $total->bind_param("i", $current_event_id);
    $total->execute();
    $total_count = $total->get_result()->fetch_assoc()['total'];
    $uniq = $conn->prepare("SELECT COUNT(DISTINCT number) as total FROM winners WHERE event_id = ?");
    $uniq->bind_param("i", $current_event_id);
    $uniq->execute();
    $unique_winners = $uniq->get_result()->fetch_assoc()['total'];
    ?>
    <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px;">
        <div style="background: #ffffff; padding: 20px; border-radius: 12px; text-align: center; border: 1px solid rgba(0,0,0,0.04);">
            <div style="font-size: 32px; font-weight: bold; color: #ec4899;"><?php echo $total_count; ?></div>
            <div style="color: #6b7280; margin-top: 5px;">Total Winners</div>
        </div>
        <div style="background: #ffffff; padding: 20px; border-radius: 12px; text-align: center; border: 1px solid rgba(0,0,0,0.04);">
            <div style="font-size: 32px; font-weight: bold; color: #ec4899;"><?php echo $unique_winners; ?></div>
            <div style="color: #6b7280; margin-top: 5px;">Unique Winners</div>
        </div>
    </div>
</div>

<?php else: ?>
<div style="text-align: center; padding: 60px; background: #faf5f7; border-radius: 16px; border: 1px solid rgba(0,0,0,0.04);">
    <h2 style="color: #4a4a6a; margin-bottom: 15px;">No Winners Yet</h2>
    <p style="color: #6b7280; margin-bottom: 40px;">Start drawing winners from the Draw section!</p>
    <a href="admin.php?page=draw" class="btn btn-primary" style="display:inline-block; margin-top:20px;">Go to Draw</a>
</div>
<?php endif; ?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"></script>
<script>
const eventName = <?php echo json_encode($current_event_name); ?>;
function exportWinnersPDF() {
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF();
    const logoUrl = 'Logo.png';

    function generatePDF(logoDataUrl) {
        if (logoDataUrl) doc.addImage(logoDataUrl, 'PNG', 10, 8, 22, 22);
        doc.setFontSize(14);
        doc.text("City Government of Koronadal", 105, 15, { align: "center" });
        doc.setFontSize(12);
        doc.text(eventName, 105, 23, { align: "center" });
        doc.setFontSize(12);
        doc.text("Raffle Winner List 2025", 105, 31, { align: "center" });

        const rows = [];
        document.querySelectorAll("table tbody tr").forEach(tr => {
            const row = [];
            tr.querySelectorAll("td").forEach(td => row.push(td.innerText));
            rows.push(row);
        });
        const headers = [];
        document.querySelectorAll("table thead th").forEach(th => headers.push(th.innerText));

        doc.autoTable({
            head: [headers],
            body: rows,
            startY: 38,
            styles: { fontSize: 9 },
            headStyles: { fillColor: [0, 0, 0], textColor: [255, 255, 255], fontStyle: 'bold' }
        });
        doc.save("list of winners raffle.pdf");
        setTimeout(function(){ showToast('PDF exported successfully!', 'success'); }, 500);
    }

    const img = new window.Image();
    img.crossOrigin = "Anonymous";
    img.onload = function() {
        const canvas = document.createElement('canvas');
        canvas.width = img.width; canvas.height = img.height;
        const ctx = canvas.getContext('2d');
        ctx.drawImage(img, 0, 0);
        generatePDF(canvas.toDataURL('image/png'));
    };
    img.onerror = function() { generatePDF(null); };
    img.src = logoUrl;
}
</script>