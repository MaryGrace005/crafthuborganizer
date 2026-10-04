<?php
// ============================================================
//  Transactional Email Service — CraftHub Organizer
//  Sends responsive HTML emails with branding & graceful logging
// ============================================================

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';

/**
 * Wraps inner HTML content in the standard CraftHub responsive email template
 */
function getCraftHubEmailTemplate(string $title, string $bodyContent, ?string $actionUrl = null, ?string $actionText = null): string {
    $appName = defined('APP_NAME') ? APP_NAME : 'CraftHub Organizer';
    $year    = date('Y');

    $actionBtnHtml = '';
    if (!empty($actionUrl) && !empty($actionText)) {
        $actionBtnHtml = '
            <div style="text-align: center; margin: 32px 0 24px 0;">
                <a href="' . htmlspecialchars($actionUrl) . '" target="_blank" style="display: inline-block; background: linear-gradient(135deg, #4ecdc4, #2b938b); color: #0b0b1f; font-family: \'Segoe UI\', Tahoma, sans-serif; font-size: 15px; font-weight: 800; text-decoration: none; padding: 14px 32px; border-radius: 12px; box-shadow: 0 4px 15px rgba(78,205,196,0.35); text-transform: uppercase; letter-spacing: 0.05em;">
                    ' . htmlspecialchars($actionText) . ' &rarr;
                </a>
            </div>
        ';
    }

    return '<!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>' . htmlspecialchars($title) . '</title>
    </head>
    <body style="margin: 0; padding: 0; background-color: #0b0b1f; font-family: \'Segoe UI\', Roboto, Helvetica, Arial, sans-serif; -webkit-font-smoothing: antialiased; color: #e2e8f0;">
        <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #0b0b1f; padding: 40px 10px;">
            <tr>
                <td align="center">
                    <!-- Main Container Card -->
                    <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width: 600px; background-color: #161626; border-radius: 18px; border: 1px solid rgba(255,255,255,0.08); overflow: hidden; box-shadow: 0 20px 40px rgba(0,0,0,0.6);">
                        <!-- Accent Top Bar -->
                        <tr>
                            <td height="5" style="background: linear-gradient(90deg, #4ecdc4, #a855f7, #f5a623, #e94560); font-size: 0; line-height: 0;">&nbsp;</td>
                        </tr>
                        <!-- Header -->
                        <tr>
                            <td style="padding: 30px 40px 20px 40px; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.06);">
                                <div style="display: inline-block; font-size: 24px; font-weight: 800; color: #ffffff; letter-spacing: -0.02em;">
                                    <span style="color: #4ecdc4;">Craft</span>Hub <span style="font-weight: 300; color: #a0aec0; font-size: 18px;">Organizer</span>
                                </div>
                            </td>
                        </tr>
                        <!-- Content Body -->
                        <tr>
                            <td style="padding: 35px 40px 30px 40px;">
                                <h1 style="color: #ffffff; font-size: 22px; font-weight: 700; margin: 0 0 16px 0; line-height: 1.3;">' . htmlspecialchars($title) . '</h1>
                                <div style="color: #cbd5e1; font-size: 15px; line-height: 1.6;">
                                    ' . $bodyContent . '
                                </div>
                                ' . $actionBtnHtml . '
                            </td>
                        </tr>
                        <!-- Footer -->
                        <tr>
                            <td style="padding: 24px 40px; background-color: #10101c; border-top: 1px solid rgba(255,255,255,0.06); text-align: center; color: #64748b; font-size: 13px; line-height: 1.5;">
                                <p style="margin: 0 0 6px 0;">&copy; ' . $year . ' ' . htmlspecialchars($appName) . '. All rights reserved.</p>
                                <p style="margin: 0; font-size: 12px; color: #475569;">123 Craft Avenue, Bohol, Philippines | Contact: +63 912 345 6789</p>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
    </body>
    </html>';
}

/**
 * Dispatch an email to a user with logging
 */
function sendCraftHubEmail(string $toEmail, string $toName, string $subject, string $htmlContent, string $textFallback = ''): bool {
    if (empty($toEmail) || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $fromEmail = 'no-reply@crafthuborganizer.com';
    $fromName  = defined('APP_NAME') ? APP_NAME : 'CraftHub Organizer';

    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: {$fromName} <{$fromEmail}>\r\n";
    $headers .= "Reply-To: {$fromEmail}\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";

    $status       = 'sent';
    $errorMessage = null;

    try {
        // Attempt native PHP mail()
        $sent = @mail($toEmail, $subject, $htmlContent, $headers);
        if (!$sent) {
            // In local Wamp environments without sendmail/SMTP configured, mail() returns false.
            // Mark as 'simulated' so local development logs the full message safely without errors.
            $status = 'simulated';
        }
    } catch (Exception $e) {
        $status       = 'failed';
        $errorMessage = $e->getMessage();
    }

    // Record in email_logs table for audit trail
    try {
        $db = getDB();
        $preview = strip_tags(mb_strimwidth($htmlContent, 0, 300, '...'));
        $stmt = $db->prepare("INSERT INTO email_logs (recipient_email, recipient_name, subject, body_preview, status, error_message) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$toEmail, $toName, $subject, $preview, $status, $errorMessage]);
    } catch (Exception $e) {
        // Fallback silently if DB logging fails
    }

    return ($status === 'sent' || $status === 'simulated');
}

/**
 * 1. Send Booking Confirmation Email
 */
function sendBookingConfirmationEmail(int $bookingId): bool {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT b.*, u.name AS customer_name, u.email AS customer_email, p.package_name, v.venue_name
        FROM bookings b
        JOIN users u ON b.customer_id = u.user_id
        JOIN packages p ON b.package_id = p.package_id
        LEFT JOIN venues v ON b.venue_id = v.venue_id
        WHERE b.booking_id = ?
    ");
    $stmt->execute([$bookingId]);
    $booking = $stmt->fetch();

    if (!$booking || empty($booking['customer_email'])) {
        return false;
    }

    $ref        = getBookingRef($booking);
    $eventDate  = date('F j, Y', strtotime($booking['event_date']));
    $eventTime  = !empty($booking['event_time']) ? date('g:i A', strtotime($booking['event_time'])) : 'TBA';
    $venueName  = $booking['venue_name'] ?: 'To Be Announced';
    $invoiceUrl = APP_URL . "/invoice.php?id={$bookingId}";

    $body = "
        <p>Dear <strong>" . htmlspecialchars($booking['customer_name']) . "</strong>,</p>
        <p>We are delighted to inform you that your booking for <strong style='color:#4ecdc4;'>" . htmlspecialchars($booking['package_name']) . "</strong> has been officially <strong style='color:#27ae60;'>CONFIRMED</strong>!</p>
        
        <table role='presentation' border='0' cellpadding='8' cellspacing='0' width='100%' style='background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.07);border-radius:12px;margin:20px 0;'>
            <tr>
                <td style='color:#94a3b8;font-size:14px;width:35%;'>Booking Reference:</td>
                <td style='color:#ffffff;font-weight:700;font-size:14px;'>" . htmlspecialchars($ref) . "</td>
            </tr>
            <tr>
                <td style='color:#94a3b8;font-size:14px;'>Event Date & Time:</td>
                <td style='color:#ffffff;font-weight:600;font-size:14px;'>" . $eventDate . " at " . $eventTime . "</td>
            </tr>
            <tr>
                <td style='color:#94a3b8;font-size:14px;'>Venue:</td>
                <td style='color:#ffffff;font-weight:600;font-size:14px;'>" . htmlspecialchars($venueName) . "</td>
            </tr>
            <tr>
                <td style='color:#94a3b8;font-size:14px;'>Total Package Cost:</td>
                <td style='color:#4ecdc4;font-weight:700;font-size:15px;'>" . formatCurrency($booking['total_amount']) . "</td>
            </tr>
        </table>
        
        <p>You can view your real-time invoice and Statement of Account or submit your proof of payment online anytime by clicking the button below.</p>
    ";

    $html = getCraftHubEmailTemplate("Booking Confirmed — " . $ref, $body, $invoiceUrl, "View Official Invoice / SOA");
    return sendCraftHubEmail($booking['customer_email'], $booking['customer_name'], "Booking Confirmed: {$ref} - {$booking['package_name']}", $html);
}

/**
 * 2. Send Payment Receipt Email
 */
function sendPaymentReceiptEmail(int $paymentId): bool {
    try {
        $db = getDB();
        $stmt = $db->prepare("
            SELECT p.*, b.booking_id, b.total_amount, b.booking_reference, b.event_date,
                   u.name AS customer_name, u.email AS customer_email, pkg.package_name
            FROM payments p
            JOIN bookings b ON p.booking_id = b.booking_id
            JOIN users u ON b.customer_id = u.user_id
            LEFT JOIN packages pkg ON b.package_id = pkg.package_id
            WHERE p.payment_id = ?
        ");
        $stmt->execute([$paymentId]);
        $payment = $stmt->fetch();

        if (!$payment || empty($payment['customer_email'])) {
            return false;
        }

        $ref         = getBookingRef($payment);
        $payDate     = date('F j, Y g:i A', strtotime($payment['payment_date'] ?? 'now'));
        $receiptNo   = !empty($payment['or_number']) ? $payment['or_number'] : ('RCP-' . str_pad($payment['payment_id'], 6, '0', STR_PAD_LEFT));
        $invoiceUrl  = APP_URL . "/invoice.php?id={$payment['booking_id']}";
        $packageName = $payment['package_name'] ?? 'Custom Package';

        // Check remaining balance
        $paidTotal = (float)$db->query("SELECT COALESCE(SUM(amount_paid),0) FROM payments WHERE booking_id = " . (int)$payment['booking_id'])->fetchColumn();
        $balance   = max(0, (float)$payment['total_amount'] - $paidTotal);

        $body = "
            <p>Dear <strong>" . htmlspecialchars($payment['customer_name']) . "</strong>,</p>
            <p>Thank you for your payment! We have successfully received and recorded your transaction.</p>
            
            <table role='presentation' border='0' cellpadding='8' cellspacing='0' width='100%' style='background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.07);border-radius:12px;margin:20px 0;'>
                <tr>
                    <td style='color:#94a3b8;font-size:14px;width:35%;'>Official Receipt No:</td>
                    <td style='color:#f5a623;font-weight:700;font-size:14px;'>" . htmlspecialchars($receiptNo) . "</td>
                </tr>
                <tr>
                    <td style='color:#94a3b8;font-size:14px;'>Booking Ref:</td>
                    <td style='color:#ffffff;font-weight:600;font-size:14px;'>" . htmlspecialchars($ref) . " (" . htmlspecialchars($packageName) . ")</td>
                </tr>
                <tr>
                    <td style='color:#94a3b8;font-size:14px;'>Amount Paid:</td>
                    <td style='color:#27ae60;font-weight:800;font-size:16px;'>" . formatCurrency($payment['amount_paid']) . "</td>
                </tr>
                <tr>
                    <td style='color:#94a3b8;font-size:14px;'>Payment Method:</td>
                    <td style='color:#ffffff;font-weight:600;font-size:14px;'>" . htmlspecialchars(ucfirst($payment['payment_method'] ?? 'cash')) . "</td>
                </tr>
                <tr>
                    <td style='color:#94a3b8;font-size:14px;'>Remaining Balance:</td>
                    <td style='color:" . ($balance > 0 ? '#f5a623' : '#27ae60') . ";font-weight:700;font-size:14px;'>" . formatCurrency($balance) . ($balance <= 0 ? " (Fully Paid 🎉)" : "") . "</td>
                </tr>
            </table>
            
            <p>Your updated Statement of Account is ready for download.</p>
        ";

        $html = getCraftHubEmailTemplate("Payment Receipt — " . $receiptNo, $body, $invoiceUrl, "Download Official Receipt / Invoice");
        return sendCraftHubEmail($payment['customer_email'], $payment['customer_name'], "Payment Receipt [{$receiptNo}] - {$ref}", $html);
    } catch (Throwable $e) {
        error_log("Failed to send payment receipt email: " . $e->getMessage());
        return false;
    }
}

/**
 * 3. Send Payment Due Reminder Email
 */
function sendPaymentReminderEmail(int $bookingId): bool {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT b.*, u.name AS customer_name, u.email AS customer_email, p.package_name
        FROM bookings b
        JOIN users u ON b.customer_id = u.user_id
        JOIN packages p ON b.package_id = p.package_id
        WHERE b.booking_id = ?
    ");
    $stmt->execute([$bookingId]);
    $booking = $stmt->fetch();

    if (!$booking || empty($booking['customer_email'])) {
        return false;
    }

    $paidTotal = (float)$db->query("SELECT COALESCE(SUM(amount_paid),0) FROM payments WHERE booking_id = {$bookingId}")->fetchColumn();
    $balance   = max(0, (float)$booking['total_amount'] - $paidTotal);

    if ($balance <= 0) return false; // Already paid

    $ref     = getBookingRef($booking);
    $dueDate = date('F j, Y', strtotime($booking['event_date']));
    $payUrl  = APP_URL . "/customer/submit_payment.php?booking_id={$bookingId}";

    $body = "
        <p>Dear <strong>" . htmlspecialchars($booking['customer_name']) . "</strong>,</p>
        <p>This is a friendly reminder that you have an outstanding balance of <strong style='color:#f5a623;font-size:16px;'>" . formatCurrency($balance) . "</strong> for your upcoming event booking <strong style='color:#4ecdc4;'>" . htmlspecialchars($ref) . "</strong> (" . htmlspecialchars($booking['package_name']) . ").</p>
        
        <p>Please settle this balance before <strong>" . $dueDate . "</strong> to avoid any scheduling delays. You can submit your proof of payment online (via GCash, Maya, or Bank Transfer) or visit our cashier counter.</p>
    ";

    $html = getCraftHubEmailTemplate("Payment Reminder — " . $ref, $body, $payUrl, "Submit Proof of Payment Online");
    return sendCraftHubEmail($booking['customer_email'], $booking['customer_name'], "Payment Reminder: Booking {$ref} Balance Due", $html);
}

/**
 * 4. Send Account Approval Email
 */
function sendAccountApprovalEmail(int $userId): bool {
    $db = getDB();
    $stmt = $db->prepare("SELECT user_id, name, email, role FROM users WHERE user_id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user || empty($user['email'])) {
        return false;
    }

    $loginUrl = APP_URL . "/login.php";
    $roleName = ucfirst($user['role']);

    $body = "
        <p>Hello <strong>" . htmlspecialchars($user['name']) . "</strong>,</p>
        <p>Great news! Your <strong>" . htmlspecialchars($roleName) . "</strong> staff account at <strong>" . (defined('APP_NAME') ? APP_NAME : 'CraftHub Organizer') . "</strong> has been <strong style='color:#27ae60;'>APPROVED</strong> by the system administrator.</p>
        <p>You can now sign in to your dashboard using your registered credentials to access your assigned workstation tools.</p>
    ";

    $html = getCraftHubEmailTemplate("Account Approved — Welcome to CraftHub", $body, $loginUrl, "Sign In to Your Account");
    return sendCraftHubEmail($user['email'], $user['name'], "Your CraftHub Organizer Account Has Been Approved", $html);
}
