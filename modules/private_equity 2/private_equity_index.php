<?php
include_once '../../partials/header.php';
?>

<div class="container mx-auto p-4">
  <h1 class="text-3xl font-bold mb-6">Private Equity Module</h1>

  <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
    <a href="scenarios.php" class="block border rounded p-6 bg-white shadow hover:shadow-lg">
      <h2 class="text-xl font-semibold mb-2">Scenario Manager</h2>
      <p>Create and manage valuation, exit, and participation scenarios.</p>
    </a>

    <a href="cap_table.php" class="block border rounded p-6 bg-white shadow hover:shadow-lg">
      <h2 class="text-xl font-semibold mb-2">Cap Table Input</h2>
      <p>Enter equity holders, convertible notes, and investment amounts.</p>
    </a>

    <a href="visualize.php" class="block border rounded p-6 bg-white shadow hover:shadow-lg">
      <h2 class="text-xl font-semibold mb-2">Ownership & ROI Charts</h2>
      <p>View visual summaries of shareholder equity, exit proceeds, and ROI.</p>
    </a>

    <a href="waterfall.php" class="block border rounded p-6 bg-white shadow hover:shadow-lg">
      <h2 class="text-xl font-semibold mb-2">Waterfall Calculator</h2>
      <p>Model distributions using preferred returns, caps, and participation tiers.</p>
    </a>

    <a href="memo.php" class="block border rounded p-6 bg-white shadow hover:shadow-lg">
      <h2 class="text-xl font-semibold mb-2">Investor Memo Generator</h2>
      <p>Export clean, formatted summaries of each deal scenario for LPs.</p>
    </a>
  </div>
</div>

<?php include_once '../../partials/footer.php'; ?>
