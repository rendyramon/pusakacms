<form action="<?php echo site_url('panel/settings/save'); ?>"></form>
<div class="row heading">
	<div class="col-md-6">
		<h1>GENERAL SETTINGS</h1>
	</div>
	<div class="col-md-6 align-right">
		<button class="btn btn-lg btn-success"><span class="fa fa-save"></span> Save settings</button>
	</div>
</div>
<ul class="nav nav-tabs">
	<?php foreach ($config as $key => $value): ?>
		<li<?php echo ($tab == $key)?' class="active"' : ''; ?>><a href="<?php echo "#".$key; ?>" role="tab" data-toggle="tab"><?php echo ucwords($key); ?></a></li>
	<?php endforeach; ?>
</ul>
<div id="myTabContent" class="tab-content">
	<?php foreach ($config as $key => $value): ?>
		<div class="tab-pane fade in <?php echo ($tab == $key)? 'active' : ''; ?>" id="<?php echo $key; ?>">
			<?php foreach ($value as $k => $v): ?>
				<div class="form-group">
					<label for="<?php echo $k; ?>"><?php echo $k; ?></label>
					<input type="text" name="<?php echo $k; ?>" value="<?php echo $v; ?>" class="form-control">
				</div>
			<?php endforeach; ?>
		</div>
	<?php endforeach; ?>
</div>
</form>