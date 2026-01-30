<?php if (strtolower($user['role']) !== 'admin') {
  echo "<p>You do not have access to the hiring pipeline.</p>";
  return;
} ?>

<h2>Hiring Pipeline</h2>
<table border="1" cellpadding="8" cellspacing="0" style="width: 90%; margin: 2rem auto;">
  <thead>
    <tr><th>Candidate</th><th>Status</th><th>Position</th></tr>
  </thead>
  <tbody>
    <tr><td>Alice Green</td><td>Interview Scheduled</td><td>Frontend Developer</td></tr>
    <tr><td>Bob Lee</td><td>Offered</td><td>Account Manager</td></tr>
  </tbody>
</table>
