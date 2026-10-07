<?php

session_start();

/*
|--------------------------------------------------------------------------
| CEK LOGIN ADMIN
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}


/*
|--------------------------------------------------------------------------
| LOAD DATABASE & HELPER
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../helpers.php';


/*
|--------------------------------------------------------------------------
| AMBIL CUSTOMER BELUM BAYAR
|--------------------------------------------------------------------------
|
| Hanya customer dengan:
|
|   status_payment = belum_bayar
|
| yang ditampilkan.
|
| Customer yang sudah mempunyai reminder dengan status pending
| tidak dapat dipilih kembali.
|
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        c.id,
        c.cid,
        c.nama,
        c.no_telepon,
        c.amount,
        c.due_date,
        c.status_payment,

        EXISTS (
            SELECT 1
            FROM reminder_logs rl
            WHERE rl.customer_id = c.id
              AND rl.status = 'pending'
        ) AS has_pending

    FROM customers c

    WHERE c.status_payment = 'belum_bayar'

    ORDER BY
        c.due_date IS NULL ASC,
        c.due_date ASC,
        c.nama ASC
";


$result = $conn->query($sql);


if (!$result) {
    die(
        'Gagal mengambil data customer: ' .
        e($conn->error)
    );
}


$customers = $result->fetch_all(MYSQLI_ASSOC);

$total = count($customers);

?>

<!DOCTYPE html>
<html lang="id">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Reminder Massal | WA Reminder</title>

    <!-- CSS SEND BULK -->
    <link
        rel="stylesheet"
        href="assets/bulk.css?v=12"
    >

</head>


<body>


<div class="bulk-page">


    <!-- =====================================================
         HEADER
    ====================================================== -->

    <header class="bulk-header">

        <div class="header-content">

            <a
                href="reminder.php"
                class="back"
            >
                &larr; Kembali ke Dashboard
            </a>


            <h1>
                Reminder Massal
            </h1>


            <p>
                Pilih customer yang belum bayar,
                lalu masukkan ke antrean reminder WhatsApp.
            </p>

        </div>


        <a
            href="logout.php"
            class="logout"
        >
            Logout
        </a>

    </header>



    <!-- =====================================================
         MAIN CARD
    ====================================================== -->

    <main class="bulk-card">


        <!-- =================================================
             CARD HEADER
        ================================================== -->

        <div class="card-head">

            <div class="card-title">

                <h2>
                    Customer Belum Bayar
                </h2>

                <p>
                    Hanya customer dengan status
                    <strong>belum_bayar</strong>
                    yang ditampilkan.
                </p>

            </div>


            <span class="count">

                <?= number_format($total) ?>

                customer

            </span>

        </div>



        <!-- =================================================
             JIKA TIDAK ADA CUSTOMER
        ================================================== -->

        <?php if ($total === 0): ?>

            <div class="empty">

                <div class="empty-icon">
                    &#10003;
                </div>

                <strong>
                    Semua customer sudah bayar
                </strong>

                <span>
                    Tidak ada customer yang perlu
                    diingatkan saat ini.
                </span>

                <a
                    href="reminder.php"
                    class="empty-button"
                >
                    Kembali ke Dashboard
                </a>

            </div>


        <?php else: ?>


            <!-- =================================================
                 FORM BULK
            ================================================== -->

            <form
                method="post"
                action="send.php"
                id="bulkForm"
            >


                <!-- CSRF TOKEN -->

                <?= csrf_field() ?>



                <!-- =================================================
                     ACTION BAR
                ================================================== -->

                <div class="bulk-actions">


                    <label class="select-all">

                        <input
                            type="checkbox"
                            id="selectAll"
                        >

                        <span>
                            Pilih semua
                        </span>

                    </label>



                    <button
                        type="submit"
                        class="send-btn"
                        id="sendButton"
                    >

                        <span>
                            Masukkan ke antrean
                        </span>

                        <span class="button-count">
                            (
                            <span id="selectedCount">0</span>
                            )
                        </span>

                    </button>

                </div>



                <!-- =================================================
                     TABLE
                ================================================== -->

                <div class="table-wrap">

                    <table>

                        <thead>

                            <tr>

                                <th class="checkbox-column">
                                    <span class="sr-only">
                                        Pilih
                                    </span>
                                </th>

                                <th>
                                    Customer
                                </th>

                                <th>
                                    No. Telepon
                                </th>

                                <th>
                                    Tagihan
                                </th>

                                <th>
                                    Jatuh Tempo
                                </th>

                                <th>
                                    Status
                                </th>

                                <th>
                                    Antrean
                                </th>

                            </tr>

                        </thead>


                        <tbody>


                        <?php foreach ($customers as $c): ?>


                            <?php

                            /*
                            |--------------------------------------------------------------------------
                            | DATA CUSTOMER
                            |--------------------------------------------------------------------------
                            */

                            $customerId = (int) $c['id'];

                            $cid = (string) $c['cid'];

                            $nama = (string) $c['nama'];

                            $phone = trim(
                                (string) $c['no_telepon']
                            );

                            $hasPhone = ($phone !== '');

                            $hasPending = (bool) $c['has_pending'];


                            /*
                            |--------------------------------------------------------------------------
                            | CUSTOMER TIDAK BOLEH DIPILIH JIKA:
                            |--------------------------------------------------------------------------
                            |
                            | 1. Sudah mempunyai reminder pending
                            | 2. Tidak mempunyai nomor telepon
                            |
                            */

                            $disabled =
                                $hasPending ||
                                !$hasPhone;

                            ?>


                            <tr
                                class="<?= $disabled ? 'row-disabled' : '' ?>"
                            >


                                <!-- =================================================
                                     CHECKBOX
                                ================================================== -->

                                <td class="checkbox-cell">

                                    <input
                                        type="checkbox"
                                        name="customer_ids[]"
                                        value="<?= $customerId ?>"
                                        class="row-check"
                                        <?= $disabled ? 'disabled' : '' ?>
                                    >

                                </td>



                                <!-- =================================================
                                     CUSTOMER
                                ================================================== -->

                                <td class="customer-cell">

                                    <strong>
                                        <?= e($nama) ?>
                                    </strong>

                                    <small>
                                        CID:
                                        <?= e($cid) ?>
                                    </small>

                                </td>



                                <!-- =================================================
                                     PHONE
                                ================================================== -->

                                <td class="phone-cell">

                                    <?php if ($hasPhone): ?>

                                        <?= e($phone) ?>

                                    <?php else: ?>

                                        <span class="phone-empty">
                                            Belum ada nomor
                                        </span>

                                    <?php endif; ?>

                                </td>



                                <!-- =================================================
                                     AMOUNT
                                ================================================== -->

                                <td class="amount-cell">

                                    <?= e(
                                        rupiah($c['amount'])
                                    ) ?>

                                </td>



                                <!-- =================================================
                                     DUE DATE
                                ================================================== -->

                                <td class="date-cell">

                                    <?= e(
                                        tanggal($c['due_date'])
                                    ) ?>

                                </td>



                                <!-- =================================================
                                     PAYMENT STATUS
                                ================================================== -->

                                <td class="status-cell">

                                    <span class="status-badge">

                                        <span class="status-dot"></span>

                                        Belum Bayar

                                    </span>

                                </td>



                                <!-- =================================================
                                     QUEUE STATUS
                                ================================================== -->

                                <td class="queue-cell">

                                    <?php if ($hasPending): ?>

                                        <span class="queue-pending">

                                            <span class="queue-dot"></span>

                                            Sudah pending

                                        </span>


                                    <?php elseif (!$hasPhone): ?>

                                        <span class="queue-empty">

                                            Nomor kosong

                                        </span>


                                    <?php else: ?>

                                        <span class="queue-ready">

                                            Belum masuk antrean

                                        </span>

                                    <?php endif; ?>

                                </td>


                            </tr>


                        <?php endforeach; ?>


                        </tbody>

                    </table>

                </div>


            </form>


        <?php endif; ?>


    </main>


</div>



<!-- =========================================================
     JAVASCRIPT
========================================================= -->

<script>

(function () {

    /*
    |--------------------------------------------------------------------------
    | ELEMENT
    |--------------------------------------------------------------------------
    */

    const selectAll =
        document.getElementById('selectAll');

    const selectedCount =
        document.getElementById('selectedCount');

    const bulkForm =
        document.getElementById('bulkForm');

    const sendButton =
        document.getElementById('sendButton');


    /*
    |--------------------------------------------------------------------------
    | JIKA FORM TIDAK ADA
    |--------------------------------------------------------------------------
    */

    if (
        !selectAll ||
        !selectedCount ||
        !bulkForm
    ) {
        return;
    }


    /*
    |--------------------------------------------------------------------------
    | CHECKBOX CUSTOMER YANG BISA DIPILIH
    |--------------------------------------------------------------------------
    */

    const checkboxes =
        Array.from(
            document.querySelectorAll(
                '.row-check:not(:disabled)'
            )
        );


    /*
    |--------------------------------------------------------------------------
    | UPDATE COUNTER
    |--------------------------------------------------------------------------
    */

    function updateCounter() {

        const selected =
            checkboxes.filter(
                function (checkbox) {

                    return checkbox.checked;

                }
            ).length;


        selectedCount.textContent =
            selected;


        /*
        |--------------------------------------------------------------------------
        | STATUS SELECT ALL
        |--------------------------------------------------------------------------
        */

        selectAll.checked =
            checkboxes.length > 0 &&
            selected === checkboxes.length;


        /*
        |--------------------------------------------------------------------------
        | INDETERMINATE
        |--------------------------------------------------------------------------
        */

        selectAll.indeterminate =
            selected > 0 &&
            selected < checkboxes.length;


        /*
        |--------------------------------------------------------------------------
        | BUTTON STATE
        |--------------------------------------------------------------------------
        */

        if (sendButton) {

            sendButton.disabled =
                selected === 0;

        }

    }


    /*
    |--------------------------------------------------------------------------
    | PILIH SEMUA
    |--------------------------------------------------------------------------
    */

    selectAll.addEventListener(
        'change',
        function () {

            checkboxes.forEach(
                function (checkbox) {

                    checkbox.checked =
                        selectAll.checked;

                }
            );


            updateCounter();

        }
    );


    /*
    |--------------------------------------------------------------------------
    | CHECKBOX INDIVIDUAL
    |--------------------------------------------------------------------------
    */

    checkboxes.forEach(
        function (checkbox) {

            checkbox.addEventListener(
                'change',
                updateCounter
            );

        }
    );


    /*
    |--------------------------------------------------------------------------
    | VALIDASI FORM
    |--------------------------------------------------------------------------
    */

    bulkForm.addEventListener(
        'submit',
        function (event) {

            const selected =
                checkboxes.some(
                    function (checkbox) {

                        return checkbox.checked;

                    }
                );


            /*
            |--------------------------------------------------------------------------
            | TIDAK ADA YANG DIPILIH
            |--------------------------------------------------------------------------
            */

            if (!selected) {

                event.preventDefault();

                alert(
                    'Pilih minimal satu customer yang belum bayar.'
                );

                return;

            }


            /*
            |--------------------------------------------------------------------------
            | KONFIRMASI
            |--------------------------------------------------------------------------
            */

            const confirmed =
                confirm(
                    'Masukkan customer terpilih ke antrean reminder WhatsApp?'
                );


            if (!confirmed) {

                event.preventDefault();

            }

        }
    );


    /*
    |--------------------------------------------------------------------------
    | INITIAL STATE
    |--------------------------------------------------------------------------
    */

    updateCounter();


})();

</script>


</body>
</html>