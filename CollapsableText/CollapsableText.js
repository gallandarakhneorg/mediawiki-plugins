function initializeCollapsableTextItemHS(num)
{
}


function toggleCollapsableTextHS(num)
{
	var sh = document.getElementById('ctsh-'+num+"-description");
	var shImg = document.getElementById('ctsh-'+num+"-togglebt");
	if(sh) {
		if (!sh.style.display || sh.style.display == 'none') {
			sh.className = 'issuedescription';
			sh.style.display = 'inline';
			shImg.src = $collapsableTextHideIcon;
		}
		else {
			 sh.style.display = 'none';
			shImg.src = $collapsableTextShowIcon;
		}
	}
}

