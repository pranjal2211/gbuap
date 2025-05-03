<?php
$is_admin = ($role === 'admin' && (!isset($_GET['tab']) || $_GET['tab'] == 'view'));
$is_detailed_view = $is_admin || $role === 'hod' || ($role === 'teacher' && ($action ?? 'view') === 'view');
?>
<div class="table-responsive">
    <table class="table table-bordered table-striped table-hover">
        <thead class="table-light">
            <tr>
                <th>Date</th>
                <?php if ($is_admin): ?>
                    <th>Student ID</th>
                    <th>Student Name</th>
                <?php elseif ($is_detailed_view): ?>
                    <th>Student</th>
                <?php endif; ?>
                <th>Subject</th>
                <?php if ($is_detailed_view): ?>
                    <th>Class</th>
                <?php endif; ?>
                <th>Status</th>
                <?php if ($is_detailed_view): ?>
                    <th>Marked By</th>
                <?php endif; ?>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($records)): ?>
                <tr>
                    <td colspan="<?= $is_admin ? 7 : ($is_detailed_view ? 6 : 3) ?>" class="text-center text-muted">
                        No attendance records found matching your criteria.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($records as $rec): ?>
                    <tr>
                        <td><?= htmlspecialchars($rec['date']) ?></td>
                        <?php if ($is_admin): ?>
                            <td><?= htmlspecialchars($rec['student_id']) ?></td>
                            <td><?= htmlspecialchars($rec['student_name']) ?></td>
                        <?php elseif ($is_detailed_view): ?>
                            <td><?= htmlspecialchars($rec['student_name']) ?></td>
                        <?php endif; ?>
                        <td><?= htmlspecialchars($rec['subject_name']) ?></td>
                        <?php if ($is_detailed_view): ?>
                            <td>
                                Yr <?= htmlspecialchars($rec['section_year']) ?>
                                Sec <?= htmlspecialchars($rec['section_name']) ?>
                                (<?= htmlspecialchars($rec['program_name']) ?>)
                            </td>
                        <?php endif; ?>
                        <td>
                            <span class="badge <?= $rec['status'] == 'present' ? 'badge-present' : 'badge-absent' ?>">
                                <?= ucfirst(htmlspecialchars($rec['status'])) ?>
                            </span>
                        </td>
                        <?php if ($is_detailed_view): ?>
                            <td><?= htmlspecialchars($rec['marked_by_name'] ?? 'System/Unknown') ?></td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
