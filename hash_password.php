<?php
// Visit http://localhost/sports-event/hash_password.php once to get hashes
// Then update the INSERT statements in database.sql

$users = [
    'admin' => 'admin123',
    'STU1' => 'student1',
    'STU2' => 'student2',
    'STU3' => 'student3',
    'STU4' => 'student4',
    'STU5' => 'student5',
    'STU6' => 'student6',
    'STU7' => 'student7',
    'STU8' => 'student8',
    'STU9' => 'student9',
    'STU10' => 'student10',
    'STU11' => 'student11',
    'STU12' => 'student12',
    'STU13' => 'student13',
    'STU14' => 'student14',
    'STU15' => 'student15',
    'STU16' => 'student16',
    'STU17' => 'student17',
    'STU18' => 'student18',
    'STU19' => 'student19',
    'STU20' => 'student20',
    'STU21' => 'student21',
    'STU22' => 'student22',
    'STU23' => 'student23',
    'STU24' => 'student24',
    'STU25' => 'student25',
    'STU26' => 'student26',
    'STU27' => 'student27',
    'STU28' => 'student28',
    'STU29' => 'student29',
    'STU30' => 'student30',
    'STU31' => 'student31',
    'STU32' => 'student32',
    'STU33' => 'student33',
    'STU34' => 'student34',
    'STU35' => 'student35',
    'STU36' => 'student36',
    'STU37' => 'student37',
    'STU38' => 'student38',
    'STU39' => 'student39',
    'STU40' => 'student40',
    'STU41' => 'student41',
    'STU42' => 'student42',
    'STU43' => 'student43',
    'STU44' => 'student44',
    'STU45' => 'student45',
    'STU46' => 'student46',
];

echo "<h2>Password Hashes</h2>";
echo "<p>Copy these into your database INSERT statements:</p>";
echo "<table border='1' cellpadding='8'>";
echo "<tr><th>Username</th><th>Password</th><th>Hash</th></tr>";
foreach ($users as $user => $pass) {
    $hash = password_hash($pass, PASSWORD_DEFAULT);
    echo "<tr><td>$user</td><td>$pass</td><td style='font-size:11px'>$hash</td></tr>";
}
echo "</table>";

// Also offer to insert directly
echo "<h3>Or run this to insert directly:</h3>";
echo "<form method='post'><button name='insert' type='submit'>Insert Users into Database</button></form>";

if (isset($_POST['insert'])) {
    require_once 'config/db.php';
    
    $userData = [
        ['admin', 'admin123', 'admin', 'Admin User', NULL, 'admin@gmail.com', '9841000000'],
        ['STU1', 'student1', 'student', 'Aarya Manandhar', 'REG001', 'aarya@gmail.com', '9841000001'],
        ['STU2', 'student2', 'student', 'Aashika Shrestha', 'REG002', 'aashika@gmail.com', '9841000002'],
        ['STU3', 'student3', 'student', 'Abhinash Shrestha', 'REG003', 'abhinash@gmail.com', '9841000003'],
        ['STU4', 'student4', 'student', 'Anjesh Pathak', 'REG004', 'anjesh@gmail.com', '9841000004'],
        ['STU5', 'student5', 'student', 'Ankit Bista', 'REG005', 'ankit@gmail.com', '9841000005'],
        ['STU6', 'student6', 'student', 'Ansh Shrestha', 'REG006', 'ansh@gmail.com', '9841000006'],
        ['STU7', 'student7', 'student', 'Ashmi Maskey', 'REG007', 'ashmi@gmail.com', '9841000007'],
        ['STU8', 'student8', 'student', 'Ashmita Adhikari', 'REG008', 'ashmita@gmail.com', '9841000008'],
        ['STU9', 'student9', 'student', 'Baivab Bhusal', 'REG009', 'baivab@gmail.com', '9841000009'],
        ['STU10', 'student10', 'student', 'Basanta Pakhrin', 'REG010', 'basanta@gmail.com', '9841000010'],
        ['STU11', 'student11', 'student', 'Bhumika Neupane', 'REG011', 'bhumika@gmail.com', '9841000011'],
        ['STU12', 'student12', 'student', 'Bibhushan Sapkota', 'REG012', 'bibhushan@gmail.com', '9841000012'],
        ['STU13', 'student13', 'student', 'Birat Jung Thapa', 'REG013', 'birat@gmail.com', '9841000013'],
        ['STU14', 'student14', 'student', 'Bishesh Pandey', 'REG014', 'bishesh@gmail.com', '9841000014'],
        ['STU15', 'student15', 'student', 'Diksha Sapkota', 'REG015', 'diksha@gmail.com', '9841000015'],
        ['STU16', 'student16', 'student', 'Dipesh Baniya', 'REG016', 'dipesh@gmail.com', '9841000016'],
        ['STU17', 'student17', 'student', 'Gandhi Raj Giri', 'REG017', 'gandhi@gmail.com', '9841000017'],
        ['STU18', 'student18', 'student', 'Janak Dangi', 'REG018', 'janak@gmail.com', '9841000018'],
        ['STU19', 'student19', 'student', 'Jubin Maharjan', 'REG019', 'jubin@gmail.com', '9841000019'],
        ['STU20', 'student20', 'student', 'Nabin Yonjan', 'REG020', 'nabin@gmail.com', '9841000020'],
        ['STU21', 'student21', 'student', 'Nirbesh Rajbhandari', 'REG021', 'nirbesh@gmail.com', '9841000021'],
        ['STU22', 'student22', 'student', 'Nirjak Bhattarai', 'REG022', 'nirjak@gmail.com', '9841000022'],
        ['STU23', 'student23', 'student', 'Niyukta Karmacharya', 'REG023', 'niyukta@gmail.com', '9841000023'],
        ['STU24', 'student24', 'student', 'Raj Babu Jirel', 'REG024', 'raj@gmail.com', '9841000024'],
        ['STU25', 'student25', 'student', 'Ramina Shrestha', 'REG025', 'ramina@gmail.com', '9841000025'],
        ['STU26', 'student26', 'student', 'Raunak Dangol', 'REG026', 'raunak@gmail.com', '9841000026'],
        ['STU27', 'student27', 'student', 'Rijendra Tamrakar', 'REG027', 'rijendra@gmail.com', '9841000027'],
        ['STU28', 'student28', 'student', 'Sabhyata Aryal', 'REG028', 'sabhyata@gmail.com', '9841000028'],
        ['STU29', 'student29', 'student', 'Sagar Dhimal', 'REG029', 'sagar@gmail.com', '9841000029'],
        ['STU30', 'student30', 'student', 'Sajendra Bajracharya', 'REG030', 'sajendra@gmail.com', '9841000030'],
        ['STU31', 'student31', 'student', 'Sarjala Pandey', 'REG031', 'sarjala@gmail.com', '9841000031'],
        ['STU32', 'student32', 'student', 'Sashant Dangol', 'REG032', 'sashant@gmail.com', '9841000032'],
        ['STU33', 'student33', 'student', 'Saumik Shrestha', 'REG033', 'saumik@gmail.com', '9841000033'],
        ['STU34', 'student34', 'student', 'Shankar Adhikari', 'REG034', 'shankar@gmail.com', '9841000034'],
        ['STU35', 'student35', 'student', 'Shivesh Shrestha', 'REG035', 'shivesh@gmail.com', '9841000035'],
        ['STU36', 'student36', 'student', 'Shrayam Manandhar', 'REG036', 'shrayam@gmail.com', '9841000036'],
        ['STU37', 'student37', 'student', 'Simon Manandhar', 'REG037', 'simon@gmail.com', '9841000037'],
        ['STU38', 'student38', 'student', 'Sital Khadka', 'REG038', 'sital@gmail.com', '9841000038'],
        ['STU39', 'student39', 'student', 'Soniya Ghimire', 'REG039', 'soniya@gmail.com', '9841000039'],
        ['STU40', 'student40', 'student', 'Spandan Bhattarai', 'REG040', 'spandan@gmail.com', '9841000040'],
        ['STU41', 'student41', 'student', 'Sudesh Godar', 'REG041', 'sudesh@gmail.com', '9841000041'],
        ['STU42', 'student42', 'student', 'Sujal Bahadur Thapa', 'REG042', 'sujal1@gmail.com', '9841000042'],
        ['STU43', 'student43', 'student', 'Sujal Shrestha', 'REG043', 'sujal2@gmail.com', '9841000043'],
        ['STU44', 'student44', 'student', 'Sujal Sthapit', 'REG044', 'sujal3@gmail.com', '9841000044'],
        ['STU45', 'student45', 'student', 'Suraj Timsina', 'REG045', 'suraj@gmail.com', '9841000045'],
        ['STU46', 'student46', 'student', 'Suyog Maharjan', 'REG046', 'suyog@gmail.com', '9841000046'],
    ];
    
    $stmt = $pdo->prepare("INSERT IGNORE INTO users (username, password, role, full_name, registration_id, email, phone) VALUES (?,?,?,?,?,?,?)");
    
    foreach ($userData as $u) {
        $u[1] = password_hash($u[1], PASSWORD_DEFAULT);
        $stmt->execute($u);
    }
    echo "<p style='color:green;font-weight:bold'>Users inserted successfully!</p>";
}
?>
