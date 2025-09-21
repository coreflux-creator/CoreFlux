<h2>Enter Time</h2>
<p>Use the form below to log your work hours.</p>

<form method="POST" action="save_time.php" style="max-width: 400px; margin: 2rem auto;">
  <label>Date:</label>
  <input type="date" name="date" required><br><br>

  <label>Hours Worked:</label>
  <input type="number" name="hours" min="0" max="24" step="0.5" required><br><br>

  <label>Description:</label><br>
  <textarea name="description" rows="4" style="width:100%;" required></textarea><br><br>

  <button type="submit">Submit</button>
</form>
