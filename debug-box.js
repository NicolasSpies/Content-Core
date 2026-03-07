var el = document.elementFromPoint(1400, 70);
var res = {
  tagName: el ? el.tagName : null,
  id: el ? el.id : null,
  className: el ? el.className : null,
  text: el ? el.innerText.trim() : null,
  rect: el ? el.getBoundingClientRect() : null
};
if (el) {
  var parent = el.parentElement;
  res.parent = {
    tagName: parent ? parent.tagName : null,
    id: parent ? parent.id : null,
    className: parent ? parent.className : null
  };
}
console.log(JSON.stringify(res, null, 2));
